"""
Job diario de pontuacao -- Graduacao Presencial.

    query 09 (BANCO_APP)  ->  modelo do mes (.pkl)  ->  TABELA_RISCO_PRESENCIAL

Uso:
    python job/pontuar_presencial.py                 # simula: salva CSV em dados/, NAO grava no banco
    python job/pontuar_presencial.py --gravar        # grava na tabela
    python job/pontuar_presencial.py --data 2026-09-22 [--gravar]   # reprocessa um dia

Sai com codigo 0 quando nao ha o que pontuar (julho, 1o semestre) e com
codigo 1 em qualquer falha -- o SQL Agent / agendador marca o passo como falho.

Conexao, por variaveis de ambiente OU por um arquivo .env na raiz (ao lado
das pastas job/, querys/ e ml/), uma por linha, no formato NOME=valor:
    EVASAO_SQL_SERVER    servidor (obrigatoria no integrador)
    EVASAO_SQL_DRIVER    padrao: ODBC Driver 17 for SQL Server
    EVASAO_SQL_USER      com usuario + senha: autenticacao SQL (servidor Linux)
    EVASAO_SQL_PASSWORD  sem eles: autenticacao Windows de quem roda
Variavel de ambiente ja definida vale mais que o .env.
Sem EVASAO_SQL_SERVER, usa o credentials.json da skill sql-server desta maquina.
Senha nunca no codigo nem no repositorio -- o .env esta no .gitignore.

Agendamento (cron do integrador, segunda e quinta depois das 06:00 -- as APIs
esperam esse calendario, ver EVASAO_DIAS_JOB):
    30 6 * * 1,4  cd /caminho/do/repo && python job/pontuar_presencial.py --gravar
"""

import argparse
import datetime as dt
import json
import logging
import math
import os
import sys

import joblib
import numpy as np
import pandas as pd
import pyodbc

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
QUERY = os.path.join(RAIZ, "querys", "09_job_presencial_diario.sql")
PKL = os.path.join(RAIZ, "ml", "grad-presencial-mensal", "modelo_evasao_presencial_mensal.pkl")
TABELA = "dbo.TABELA_RISCO_PRESENCIAL"
SAIDA = os.path.join(RAIZ, "dados", "job_presencial")

# Faixas pela POSICAO na fila do dia. Todas proporcionais a base (24/09/2026):
# a fila acompanha o tamanho da turma em vez de ficar presa a um numero fixo.
# Quem passa da faixa MEDIA nao e gravado: ausencia na tabela = sem risco.
# Medido na coorte 2025/2 (OOF): a CRITICA acerta ~41%, igual ao topo 200
# antigo; o que muda e o tamanho (~2% da base, nao a capacidade da equipe).
PCT_ALTA = 0.10        # ate 10% da base do dia
PCT_MEDIA = 0.20        # ate 20% da base do dia
PCT_CRITICA = 0.20      # os 20% do topo da faixa ALTA (= 2% da base)

# Abaixo disso, a query provavelmente falhou pela metade (semestre em
# andamento tem ~9.400). Melhor nao gravar nada do que gravar uma fila errada.
MIN_ALUNOS = 5000

# Colunas de texto da query 09. Todo o resto e numero: o pyodbc devolve
# decimal como objeto Decimal, e o get_dummies o trataria como categoria.
TEXTO = {"ALUNO", "CURSO", "NOME_CURSO", "MODALIDADE_CURSO", "TURNO", "UNIDADE_FISICA",
         "SEXO", "EST_CIVIL", "NACIONALIDADE", "END_MUNICIPIO"}
DATAS = {"DT_CORTE", "DT_INICIO_OBS"}

PARAM_CORTE = "CAST(NULL AS date)                  AS DT_CORTE_MANUAL"

log = logging.getLogger("pontuar_presencial")
ENV = os.path.join(RAIZ, ".env")


def carregar_env():
    """Le o .env da raiz, se existir. Nao sobrescreve variavel ja definida."""
    if not os.path.exists(ENV):
        return
    with open(ENV, encoding="utf-8-sig") as f:
        for linha in f:
            linha = linha.strip()
            if not linha or linha.startswith("#") or "=" not in linha:
                continue
            nome, valor = linha.split("=", 1)
            nome, valor = nome.strip(), valor.strip()
            if len(valor) >= 2 and valor[0] == valor[-1] and valor[0] in "'\"":
                valor = valor[1:-1]
            if nome and valor and not os.environ.get(nome):
                os.environ[nome] = valor


def conexao():
    servidor = os.environ.get("EVASAO_SQL_SERVER")
    driver = os.environ.get("EVASAO_SQL_DRIVER", "ODBC Driver 17 for SQL Server")
    if not servidor:
        creds = os.path.expanduser("~/.claude/skills/sql-server/credentials.json")
        with open(creds, encoding="utf-8") as f:
            c = json.load(f)
        servidor, driver = c["server"], c.get("driver", driver)
    usuario, senha = os.environ.get("EVASAO_SQL_USER"), os.environ.get("EVASAO_SQL_PASSWORD")
    if usuario and senha:
        # entre chaves: senha com ; ou = quebraria a string de conexao ODBC
        chaves = lambda v: "{" + v.replace("}", "}}") + "}"
        auth = f"UID={chaves(usuario)};PWD={chaves(senha)}"
    elif usuario or senha:
        raise RuntimeError("defina EVASAO_SQL_USER e EVASAO_SQL_PASSWORD juntos, ou nenhum dos dois")
    else:
        auth = "Trusted_Connection=yes"
    log.info("conectando em %s (%s)", servidor, "usuario SQL" if usuario else "autenticacao Windows")
    return pyodbc.connect(
        f"DRIVER={{{driver}}};SERVER={servidor};DATABASE=BANCO_APP;{auth}",
        timeout=30)


def extrair(con, data):
    with open(QUERY, encoding="utf-8") as f:
        sql = f.read()
    if data:
        # a linha do parametro tem que existir exatamente uma vez
        if sql.count(PARAM_CORTE) != 1:
            raise RuntimeError("linha do DT_CORTE_MANUAL nao encontrada na query 09")
        sql = sql.replace(PARAM_CORTE, f"CAST('{data.isoformat()}' AS date) AS DT_CORTE_MANUAL")
    cur = con.cursor()
    cur.execute(sql)
    colunas = [c[0] for c in cur.description]
    df = pd.DataFrame.from_records(cur.fetchall(), columns=colunas)
    for c in df.columns:
        if c in TEXTO:
            df[c] = df[c].astype("string").str.strip().astype(object).where(df[c].notna(), np.nan)
        elif c not in DATAS:
            df[c] = pd.to_numeric(df[c], errors="coerce")
    return df


def montar_x(d, art, mes):
    # Mesma engenharia do notebook (topico 3). O agrupamento de municipio e a
    # mediana vem do ARTEFATO -- recalcular sobre a turma nova daria colunas
    # diferentes das que o modelo espera.
    d = d.copy()
    d["EST_CIVIL"] = (d["EST_CIVIL"].astype(str).str.upper()
                      .replace({"NAN": "NAO_INFORMADO", "NONE": "NAO_INFORMADO"}))
    principais = set(art["principais_municipios"][mes])
    d["END_MUNICIPIO_AGRUPADO"] = d["END_MUNICIPIO"].apply(
        lambda x: str(x) if x in principais else "OUTRO")
    d["RATIO_GAP_ATIVOS"] = d["AVA_MAIOR_GAP_DIAS"] / (d["AVA_DIAS_ATIVOS"] + 1)
    d["SESSOES_POR_DIA"] = d["AVA_QTD_SESSOES"] / (d["AVA_DIAS_ATIVOS"] + 1)

    X = d.drop(columns=[c for c in art["colunas_remover"] if c in d.columns])
    cat = [c for c in ("CURSO", "END_MUNICIPIO_AGRUPADO") if c in X.columns]
    # Sem drop_first: a categoria de referencia do treino nao esta em
    # colunas_modelo, entao o reindex a zera -- igual ao treino. Com
    # drop_first, se a turma nova nao tivesse o mesmo "primeiro" curso, outra
    # categoria seria descartada no lugar. Curso novo, que o treino nao viu,
    # tambem vira zero em tudo (= categoria de referencia).
    X = pd.get_dummies(X, columns=cat)
    colunas = art["colunas_modelo"][mes]
    novos = sorted(set(d["CURSO"].dropna()) - {c[len("CURSO_"):] for c in colunas if c.startswith("CURSO_")})
    if novos:
        log.info("cursos sem coluna no modelo (tratados como referencia): %d", len(novos))
    X = X.reindex(columns=colunas, fill_value=0).astype(float)
    X = X.fillna(art["medianas"][mes].reindex(colunas))
    modelo = art["modelos"][mes]
    assert modelo.n_features_in_ == X.shape[1]
    return X


def classificar(d, proba):
    n = len(d)
    r = pd.DataFrame({"DT_CORTE": d["DT_CORTE"].values, "ALUNO": d["ALUNO"].astype(str).values,
                      "CURSO": d["CURSO"].values, "PROB": proba})
    r = r.sort_values("PROB", ascending=False, kind="mergesort").reset_index(drop=True)
    r["POSICAO"] = np.arange(1, n + 1)
    lim_alta, lim_media = math.ceil(PCT_ALTA * n), math.ceil(PCT_MEDIA * n)
    lim_critica = math.ceil(PCT_CRITICA * lim_alta)   # fatia de cima da propria ALTA
    r["CHANCE_EVASAO"] = np.select(
        [r["POSICAO"] <= lim_critica, r["POSICAO"] <= lim_alta, r["POSICAO"] <= lim_media],
        ["CRITICA", "ALTA", "MEDIA"], default="")
    return r[r["CHANCE_EVASAO"] != ""]


# MOTIVO/MOTIVO2/MOTIVO3 -- "onde o aluno tem mais dificuldade".
# Heuristica "pior que a turma": para cada feature acionavel, mede quao extremo
# o aluno esta vs a coorte do dia (percentil na direcao de risco) e, entre as que
# passam de um limiar absoluto (gate), pega as 3 mais graves. NAO e a atribuicao
# do modelo (isso seria SHAP) -- e descritivo, uso interno (LGPD).
#
# So entram features que a query 09 produz (AVA + academico). O financeiro NAO
# esta no job (bloco 10 da query 09 esta desativado por vazamento); ele entra AO
# VIVO na API. Cada entrada: (grupo, coluna, direcao, gate, frase, prioridade).
# 'high' = quanto maior pior; 'low' = quanto menor pior. O gate usa as unidades
# reais medidas em 05/10/2026 (freq/aprovacao 0-1, nota 0-10). Um grupo so rende
# UM motivo (o mais grave) -- evita dois sinais de AVA no mesmo aluno.
# Gates com 0 < v: no semestre em curso, nota/frequencia/aprovacao vem 0 quando
# ainda NAO foram lancadas -- isso e "sem dado", nao "ruim". Excluir o zero evita
# o falso "notas baixas (media 0.0)". A prioridade (ultimo campo) desempata DENTRO
# do grupo: menor numero vence, entao em AVA a frase de dias-sem-acesso (1) ganha
# de "pouco acesso" (2) quando as duas disparam.
MOTIVOS_WL = [
    ("AVA",   "AVA_DIAS_DESDE_ULT_ACESSO", "high", lambda v: v > 14,          lambda v: f"sem acessar o AVA ha {int(v)} dias", 1),
    ("AVA",   "AVA_TAXA_DIAS_ATIVOS",      "low",  lambda v: v < 0.08,         lambda v: "pouco acesso ao AVA", 2),
    ("FREQ",  "FREQ_PONDERADA",            "low",  lambda v: 0 < v < 0.75,     lambda v: f"frequencia baixa ({v * 100:.0f}%)", 3),
    ("NOTA",  "NOTA_MEDIA",                "low",  lambda v: 0 < v < 6.0,      lambda v: f"notas baixas (media {math.floor(v * 10) / 10:.1f})", 4),
    ("NOTA",  "TAXA_APROVACAO",            "low",  lambda v: 0 < v < 0.6,      lambda v: "baixa aprovacao nas disciplinas", 5),
    ("HIST",  "DISC_REP_NOTA_ANT",         "high", lambda v: v >= 2,           lambda v: f"reprovacoes no semestre anterior ({int(v)})", 6),
    ("CARGA", "DISC_MATRICULADAS_INICIO",  "low",  lambda v: v <= 3,           lambda v: f"carga baixa ({int(v)} disciplina{'s' if v != 1 else ''})", 7),
]


def motivos_coorte(d):
    """Top-3 motivos por aluno, calculados sobre a coorte do dia inteira.
    Devolve DataFrame (ALUNO, MOTIVO, MOTIVO2, MOTIVO3) -- texto ou None."""
    por_aluno = [dict() for _ in range(len(d))]   # idx -> grupo -> (severidade, prio, frase)
    for grupo, col, direcao, gate, frase, prio in MOTIVOS_WL:
        if col not in d.columns:
            continue
        v = pd.to_numeric(d[col], errors="coerce")
        # percentil na direcao de risco: 'high' asc (valor alto = grave),
        # 'low' desc (valor baixo = grave). NaN fica NaN e nao vira motivo.
        sev = v.rank(pct=True, ascending=(direcao == "high"))
        mask = v.notna() & v.map(lambda x: bool(gate(x)) if pd.notna(x) else False)
        for i in np.flatnonzero(mask.to_numpy()):
            s = float(sev.iat[i])
            atual = por_aluno[i].get(grupo)
            # dentro do grupo: menor prioridade vence; empate, maior severidade
            if atual is None or (prio, -s) < (atual[1], -atual[0]):
                por_aluno[i][grupo] = (s, prio, frase(v.iat[i]))
    m1, m2, m3 = [], [], []
    for cand in por_aluno:
        # mais grave primeiro (severidade desc); empate pela prioridade do grupo
        tri = [c[2] for c in sorted(cand.values(), key=lambda t: (-t[0], t[1]))[:3]]
        tri += [None] * (3 - len(tri))
        m1.append(tri[0]); m2.append(tri[1]); m3.append(tri[2])
    return pd.DataFrame({"ALUNO": d["ALUNO"].astype(str).values,
                         "MOTIVO": m1, "MOTIVO2": m2, "MOTIVO3": m3})


def gravar(con, r, dia):
    cur = con.cursor()
    cur.fast_executemany = True
    txt = lambda x: x if isinstance(x, str) else None   # NaN/None -> NULL
    try:
        # reprocessar o mesmo dia nao duplica: apaga so aquele DT_CORTE
        cur.execute(f"DELETE FROM {TABELA} WHERE DT_CORTE = ?", dia)
        cur.executemany(
            f"INSERT INTO {TABELA} (DT_CORTE, ALUNO, CHANCE_EVASAO, CURSO, MOTIVO, MOTIVO2, MOTIVO3) "
            "VALUES (?, ?, ?, ?, ?, ?, ?)",
            [(dia, a, c, txt(cu), txt(m1), txt(m2), txt(m3))
             for a, c, cu, m1, m2, m3 in zip(r["ALUNO"], r["CHANCE_EVASAO"], r["CURSO"],
                                             r["MOTIVO"], r["MOTIVO2"], r["MOTIVO3"])])
        con.commit()
    except Exception:
        con.rollback()
        raise


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--data", type=dt.date.fromisoformat, help="DT_CORTE a reprocessar (padrao: ontem)")
    ap.add_argument("--gravar", action="store_true", help="grava em TABELA_RISCO_PRESENCIAL")
    a = ap.parse_args()
    carregar_env()

    os.makedirs(SAIDA, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
        handlers=[logging.StreamHandler(sys.stdout),
                  logging.FileHandler(os.path.join(SAIDA, "pontuar_presencial.log"), encoding="utf-8")])

    art = joblib.load(PKL)
    log.info("modelo %s", art.get("versao", "SEM VERSAO"))
    if "colunas_modelo" not in art:
        raise RuntimeError("o .pkl e anterior a revisao de 23/09/2026 (sem colunas_modelo) -- retreine")

    with conexao() as con:
        d = extrair(con, a.data)
        if d.empty:
            log.info("query nao devolveu alunos (fora de semestre letivo?) -- nada a pontuar")
            return 0
        dia = pd.to_datetime(d["DT_CORTE"].iloc[0]).date()
        mes = d["MODELO_MES"].iloc[0]
        log.info("corte %s | %d alunos | MODELO_MES=%s", dia, len(d), mes)

        if pd.isna(mes):
            log.info("sem modelo validado para este mes (julho ou 1o semestre) -- nada a pontuar")
            return 0
        mes = int(mes)
        if d["DT_CORTE"].nunique() != 1 or d["ALUNO"].duplicated().any():
            raise RuntimeError("query devolveu mais de um corte ou aluno repetido")
        if len(d) < MIN_ALUNOS:
            raise RuntimeError(f"so {len(d)} alunos (minimo {MIN_ALUNOS}) -- nao gravo fila incompleta")

        proba = art["modelos"][mes].predict_proba(montar_x(d, art, mes))[:, 1]
        r = classificar(d, proba)
        # motivos calculados sobre a coorte inteira, anexados aos que entram na fila
        r = r.merge(motivos_coorte(d), on="ALUNO", how="left")
        log.info("faixas: %s", r["CHANCE_EVASAO"].value_counts().to_dict())
        log.info("com pelo menos 1 motivo: %d de %d", r["MOTIVO"].notna().sum(), len(r))

        csv = os.path.join(SAIDA, f"risco_presencial_{dia.isoformat()}.csv")
        r.to_csv(csv, index=False, encoding="utf-8-sig")
        log.info("CSV salvo em %s", csv)

        if a.gravar:
            gravar(con, r, dia)
            log.info("gravado em %s: %d linhas para %s", TABELA, len(r), dia)
        else:
            log.info("simulacao -- nada gravado no banco (use --gravar)")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        log.exception("falhou")
        sys.exit(1)
