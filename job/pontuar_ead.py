"""
Job periodico de pontuacao -- Graduacao EAD.

    query 12 (BANCO_APP)  ->  modelo do corte (.pkl)  ->  TABELA_RISCO_EAD

Gemeo de pontuar_presencial.py. A diferenca esta so na engenharia de features
(o EAD tem ranks de AVA dentro da coorte, SEM_AVA, e NAO imputa numerico) e na
tabela de destino.

Uso:
    python job/pontuar_ead.py                 # simula: salva CSV em dados/, NAO grava no banco
    python job/pontuar_ead.py --gravar        # grava na tabela
    python job/pontuar_ead.py --data 2026-07-30 [--gravar]   # reprocessa um dia

Sai com codigo 0 quando nao ha o que pontuar (corte fora de qualquer
trimestre letivo -- a query devolve zero linhas) e com codigo 1 em qualquer
falha, para o SQL Agent / agendador marcar o passo como falho.

A trava dos 30 dias saiu em 05/10/2026: o m1 vale desde o dia 1 do trimestre.
Ver o cabecalho de querys/12_job_ead_periodico.sql para o custo medido.

Conexao, por variaveis de ambiente OU por um arquivo .env na raiz (ao lado das
pastas job/, querys/ e ml/), uma por linha, no formato NOME=valor:
    EVASAO_SQL_SERVER    servidor (obrigatoria no integrador)
    EVASAO_SQL_DRIVER    padrao: ODBC Driver 17 for SQL Server
    EVASAO_SQL_USER      com usuario + senha: autenticacao SQL (servidor Linux)
    EVASAO_SQL_PASSWORD  sem eles: autenticacao Windows de quem roda
Variavel de ambiente ja definida vale mais que o .env.
Sem EVASAO_SQL_SERVER, usa o credentials.json da skill sql-server desta maquina.
Senha nunca no codigo nem no repositorio -- o .env esta no .gitignore.

Agendamento (cron do integrador, segunda e quinta depois das 06:00 -- a API do
EAD espera esse calendario, ver EVASAO_DIAS_JOB):
    30 6 * * 1,4  cd /caminho/do/repo && python job/pontuar_ead.py --gravar
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
QUERY = os.path.join(RAIZ, "querys", "12_job_ead_periodico.sql")
PKL = os.path.join(RAIZ, "ml", "grad-ead", "modelo_evasao_ead_mensal.pkl")
TABELA = "dbo.TABELA_RISCO_EAD"
SAIDA = os.path.join(RAIZ, "dados", "job_ead")

# Abaixo disso a query provavelmente falhou pela metade (um trimestre do EAD
# tem ~9.000 alunos). Melhor nao gravar nada do que gravar uma fila errada.
# Nao confundir com corte fora de trimestre letivo: la a query devolve zero
# linhas e o job sai antes, com 0, sem tratar como erro.
MIN_ALUNOS = 4000

# Colunas de texto da query 12. Todo o resto e numero: o pyodbc devolve decimal
# como objeto Decimal, e o get_dummies o trataria como categoria.
TEXTO = {"ALUNO", "CURSO", "NOME_CURSO", "MODALIDADE_CURSO", "TURNO", "UNIDADE_FISICA",
         "SEXO", "EST_CIVIL", "NACIONALIDADE", "END_MUNICIPIO"}
DATAS = {"DT_CORTE", "DT_INICIO_OBS"}

PARAM_CORTE = "CAST(NULL AS date)                  AS DT_CORTE_MANUAL"

log = logging.getLogger("pontuar_ead")
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
            raise RuntimeError("linha do DT_CORTE_MANUAL nao encontrada na query 12")
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
    """Reproduz a engenharia do notebook (topicos 3, 5 e 5-encoding) sobre a
    turma nova. As categorias de CURSO/municipio e a lista de colunas vem do
    ARTEFATO -- recalcular sobre a turma nova daria colunas diferentes das que
    o modelo espera.

    Diferencas para o presencial:
      - ranks de AVA (pct) sao computados na coorte pontuada (a do dia);
      - SEM_AVA marca quem nao tem nenhum dado de AVA;
      - categorias fora do agrupamento do treino viram OUTRO (nao referencia);
      - numerico NAO e imputado: o RandomForest >= 1.4 trata NaN nativamente,
        e foi assim que o modelo treinou (o .pkl nao guarda medianas).
    """
    d = d.copy()
    ava = [c for c in d.columns if c.startswith("AVA_")]

    # ranks dentro da coorte pontuada (ha um unico DT_CORTE por execucao)
    for c in ava:
        d[c + "_RANK"] = d[c].rank(pct=True)
    d["RATIO_GAP_ATIVOS"] = d["AVA_MAIOR_GAP_DIAS"] / (d["AVA_DIAS_ATIVOS"] + 1)
    d["SESSOES_POR_DIA"] = d["AVA_QTD_SESSOES"] / (d["AVA_DIAS_ATIVOS"] + 1)
    d["SEM_AVA"] = d[ava].isna().all(axis=1).astype(int)

    # agrupamento da cauda longa: a categoria que o treino nao viu vira OUTRO,
    # para cair na mesma coluna *_OUTRO (reindex sozinho a zeraria como
    # referencia, que e outra coisa). So CURSO e END_MUNICIPIO tiveram OUTRO no
    # treino (top 25); as demais categoricas o reindex trata.
    grupos = art["agrupamentos"][mes]
    for c in ("CURSO", "END_MUNICIPIO"):
        if c in d.columns and c in grupos:
            validos = set(grupos[c])
            d[c] = d[c].apply(lambda x: str(x) if (pd.notna(x) and str(x) in validos) else "OUTRO")

    # one-hot das categoricas (mesmas colunas que o treino codificou)
    cat = [c for c in d.columns if c in grupos]
    for c in cat:
        d[c] = d[c].fillna("NAO_INFORMADO").astype(str)
    X = pd.get_dummies(d, columns=cat)

    # alinha exatamente as colunas do modelo. Coluna nova (curso/municipio que
    # o treino nao viu, fora do OUTRO) e descartada; coluna ausente vira 0.
    colunas = art["colunas_modelo"][mes]
    X = X.reindex(columns=colunas, fill_value=0)
    # numerico fica com o que veio (NaN inclusive); so as colunas novas do
    # reindex entraram como 0. Converte para float sem imputar.
    X = X.astype(float)

    modelo = art["modelos"][mes]
    assert modelo.n_features_in_ == X.shape[1], (modelo.n_features_in_, X.shape[1])
    return X


def classificar(d, proba, faixas):
    n = len(d)
    r = pd.DataFrame({"DT_CORTE": d["DT_CORTE"].values, "ALUNO": d["ALUNO"].astype(str).values,
                      "CURSO": d["CURSO"].values, "PROB": proba})
    r = r.sort_values("PROB", ascending=False, kind="mergesort").reset_index(drop=True)
    r["POSICAO"] = np.arange(1, n + 1)
    lim_alta = math.ceil(faixas["PCT_ALTA"] * n)
    lim_media = math.ceil(faixas["PCT_MEDIA"] * n)
    lim_critica = math.ceil(faixas["PCT_CRITICA_DA_ALTA"] * lim_alta)  # fatia de cima da propria ALTA
    r["CHANCE_EVASAO"] = np.select(
        [r["POSICAO"] <= lim_critica, r["POSICAO"] <= lim_alta, r["POSICAO"] <= lim_media],
        ["CRITICA", "ALTA", "MEDIA"], default="")
    return r[r["CHANCE_EVASAO"] != ""]


# MOTIVO/MOTIVO2/MOTIVO3 -- "onde o aluno tem mais dificuldade".
# Mesma heuristica "pior que a turma" do presencial (pontuar_presencial.py): para
# cada feature acionavel, mede quao extremo o aluno esta vs a coorte do dia
# (percentil na direcao de risco) e, entre as que passam de um gate absoluto, pega
# as 3 mais graves. Descritivo, uso interno (LGPD) -- NAO e a atribuicao do modelo.
#
# Diferencas para o presencial, todas por causa da modalidade:
#   - SEM bloco FREQ: o EAD nao tem frequencia/faltas (nao ha aula presencial).
#   - SEM bloco financeiro: embora a query 12 produza FIN_*, o financeiro entra AO
#     VIVO na API (consultar.php soma fator_financeiro_texto por cima dos motivos
#     gravados, nas duas modalidades). Grava-lo aqui duplicaria o fator.
#   - "trimestre anterior" no lugar de "semestre anterior" (o EAD e trimestral).
# O resto e identico: AVA (2 regras), nota media, aprovacao, reprovacoes T-1, carga.
# Um grupo rende UM motivo (o mais grave); menor prioridade desempata dentro do grupo.
# Gates com 0 < v: nota/aprovacao vem 0 quando ainda nao foi lancada no trimestre em
# curso -- isso e "sem dado", nao "ruim"; excluir o zero evita o falso "nota 0.0".
MOTIVOS_WL = [
    ("AVA",   "AVA_DIAS_DESDE_ULT_ACESSO", "high", lambda v: v > 14,       lambda v: f"sem acessar o AVA ha {int(v)} dias", 1),
    ("AVA",   "AVA_TAXA_DIAS_ATIVOS",      "low",  lambda v: v < 0.08,      lambda v: "pouco acesso ao AVA", 2),
    ("NOTA",  "NOTA_MEDIA",                "low",  lambda v: 0 < v < 6.0,   lambda v: f"notas baixas (media {math.floor(v * 10) / 10:.1f})", 4),
    ("NOTA",  "TAXA_APROVACAO",            "low",  lambda v: 0 < v < 0.6,   lambda v: "baixa aprovacao nas disciplinas", 5),
    ("HIST",  "DISC_REP_NOTA_ANT",         "high", lambda v: v >= 2,        lambda v: f"reprovacoes no trimestre anterior ({int(v)})", 6),
    ("CARGA", "DISC_MATRICULADAS_INICIO",  "low",  lambda v: v <= 2,        lambda v: f"carga baixa ({int(v)} disciplina{'s' if v != 1 else ''})", 7),
]


def motivos_coorte(d):
    """Top-3 motivos por aluno, calculados sobre a coorte do dia inteira.
    Devolve DataFrame (ALUNO, MOTIVO, MOTIVO2, MOTIVO3) -- texto ou None.
    Copia da logica de pontuar_presencial.py; so a whitelist (MOTIVOS_WL) muda."""
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
    # SEM_AVA -- quem nao tem NENHUM dado de AVA (sem conta no AVA ou nunca
    # acessou) e o grupo de MAIOR risco do EAD ("o AVA e a sala de aula"). As
    # colunas AVA_* vem NaN, entao NENHUMA regra de AVA acima dispara (os gates
    # exigem numero) -- sem isto, o topo da fila sai sem motivo. Entra como o
    # motivo AVA de topo, com o MESMO texto do fallback ao vivo da API
    # (fator_ava_texto): prioridade 0 (ganha de "sem acessar ha N dias") e
    # severidade 1.0 (surge como 1o motivo). So no EAD: la a ausencia e abandono.
    ava_cols = [c for c in d.columns if c.startswith("AVA_")]
    if ava_cols:
        sem_ava = d[ava_cols].isna().all(axis=1).to_numpy()
        for i in np.flatnonzero(sem_ava):
            por_aluno[i]["AVA"] = (1.0, 0, "sem registro de acesso ao AVA")
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
    ap.add_argument("--gravar", action="store_true", help="grava em TABELA_RISCO_EAD")
    a = ap.parse_args()
    carregar_env()

    os.makedirs(SAIDA, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
        handlers=[logging.StreamHandler(sys.stdout),
                  logging.FileHandler(os.path.join(SAIDA, "pontuar_ead.log"), encoding="utf-8")])

    art = joblib.load(PKL)
    log.info("modelo %s", art.get("versao", "SEM VERSAO"))
    if "colunas_modelo" not in art:
        raise RuntimeError("o .pkl nao tem colunas_modelo -- retreine o modelo mensal do EAD")

    with conexao() as con:
        d = extrair(con, a.data)
        if d.empty:
            log.info("query nao devolveu alunos (fora de trimestre letivo?) -- nada a pontuar")
            return 0
        dia = pd.to_datetime(d["DT_CORTE"].iloc[0]).date()
        mes = d["MODELO_MES"].iloc[0]
        log.info("corte %s | %d alunos | MODELO_MES=%s | trimestre %s/%s",
                 dia, len(d), mes, d["ANO_OBS"].iloc[0], d["PERIODO_OBS"].iloc[0])

        if pd.isna(mes):
            # Desde 05/10/2026 a query devolve 1 para qualquer corte dentro de
            # um trimestre, entao isto so acontece em caso inesperado. A guarda
            # fica: melhor sair sem gravar do que pontuar com modelo indefinido.
            log.info("MODELO_MES nulo -- nada a pontuar")
            return 0
        mes = int(mes)
        if d["DT_CORTE"].nunique() != 1 or d["ALUNO"].duplicated().any():
            raise RuntimeError("query devolveu mais de um corte ou aluno repetido")
        if len(d) < MIN_ALUNOS:
            raise RuntimeError(f"so {len(d)} alunos (minimo {MIN_ALUNOS}) -- nao gravo fila incompleta")

        proba = art["modelos"][mes].predict_proba(montar_x(d, art, mes))[:, 1]
        r = classificar(d, proba, art["faixas"])
        # motivos calculados sobre a coorte inteira, anexados aos que entram na fila
        r = r.merge(motivos_coorte(d), on="ALUNO", how="left")
        log.info("faixas: %s", r["CHANCE_EVASAO"].value_counts().to_dict())
        log.info("com pelo menos 1 motivo: %d de %d", r["MOTIVO"].notna().sum(), len(r))

        csv = os.path.join(SAIDA, f"risco_ead_{dia.isoformat()}.csv")
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
