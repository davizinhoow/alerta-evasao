"""Pontua a turma corrente do HIBRIDO e grava a fila em TABELA_RISCO_HIBRIDO.

    python job/pontuar_hibrido.py                 # so mostra, nao grava
    python job/pontuar_hibrido.py --gravar        # grava na tabela
    python job/pontuar_hibrido.py --data 2026-07-30 [--gravar]   # reprocessa um dia

Sai com codigo 0 quando nao ha o que pontuar (corte fora de qualquer trimestre
letivo -- a query devolve zero linhas) e com codigo 1 em qualquer falha, para o
SQL Agent / agendador marcar o passo como falho.

Gemeo de job/pontuar_ead.py. As DUAS diferencas que importam:

  1. CURSO E NORMALIZADO pelos tres ultimos digitos, ANTES do agrupamento.
     Seis cursos do hibrido tem dois codigos (2111/8111 Nutricao, 2256/8256
     Biomedicina...), sempre com o mesmo sufixo -- sao 38,5% da base. O .pkl
     foi treinado com o codigo normalizado (o agrupamento guarda '111', nao
     '2111'). Sem normalizar aqui, TODO aluno cairia em OUTRO e o modelo nao
     reconheceria ninguem. Ver CLAUDE.md, "CURSO duplicado no hibrido".

  2. A coluna de modelo chama MODELO_CORTE (nao MODELO_MES) e sai por DIAS
     desde o inicio do trimestre: <=30 -> m1, <=60 -> m2, >60 -> m3. Os
     trimestres do hibrido duram de 66 a 120 dias, entao regra de calendario
     nao serve.

RESSALVA (02/10/2026): o modelo do hibrido EMPATA com uma regra de uma coluna
(ordenar por AVA_DIAS_DESDE_ULT_ACESSO, maior primeiro). Margens de -18 a +9
numa fila de 547, medidas em duas origens. Este script existe para o job ser
possivel, nao porque o modelo esteja aprovado -- ver CLAUDE.md, "O hibrido nao
bate a regra de uma coluna".

Conexao, por variaveis de ambiente OU por um arquivo .env na raiz (ao lado das
pastas job/, querys/ e ml/), uma por linha, no formato NOME=valor:

    EVASAO_SQL_SERVER   obrigatorio
    EVASAO_SQL_USER     e EVASAO_SQL_PASSWORD -- juntos, ou nenhum dos dois
                        (sem eles usa autenticacao Windows)
    EVASAO_SQL_DRIVER   padrao "ODBC Driver 17 for SQL Server"
"""
from __future__ import annotations

import argparse
import logging
import json
import math
import os
import sys

import joblib
import numpy as np
import pandas as pd
import pyodbc

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
QUERY = os.path.join(RAIZ, "querys", "11_job_hibrido_diario.sql")
ARTEFATO = os.path.join(RAIZ, "ml", "grad-hib", "modelo_evasao_hibrido_mensal.pkl")
TABELA = "dbo.TABELA_RISCO_HIBRIDO"
ENV = os.path.join(RAIZ, ".env")

# Abaixo disso a query provavelmente falhou pela metade. O hibrido tem 2.200 a
# 3.200 alunos por dia (contra ~9.000 do EAD), entao o piso e proporcionalmente
# menor. Melhor nao gravar nada do que gravar uma fila errada.
# Nao confundir com corte fora de trimestre letivo: la a query devolve zero
# linhas e o job sai antes, com 0, sem tratar como erro.
MIN_ALUNOS = 1500

log = logging.getLogger("pontuar_hibrido")


def carregar_env():
    """Le o .env da raiz, se existir. Variavel ja definida no ambiente vence."""
    if not os.path.exists(ENV):
        return
    with open(ENV, encoding="utf-8") as f:
        for linha in f:
            linha = linha.strip()
            if not linha or linha.startswith("#") or "=" not in linha:
                continue
            nome, valor = linha.split("=", 1)
            nome, valor = nome.strip(), valor.strip()
            if len(valor) >= 2 and valor[0] == valor[-1] and valor[0] in "'\"":
                valor = valor[1:-1]
            os.environ.setdefault(nome, valor)


def conexao():
    """Mesma cadeia do pontuar_ead.py: ambiente > .env da raiz > credentials.json
    da skill sql-server (so para rodar na maquina do dev; no integrador a
    EVASAO_SQL_SERVER e obrigatoria)."""
    carregar_env()
    servidor = os.environ.get("EVASAO_SQL_SERVER")
    driver = os.environ.get("EVASAO_SQL_DRIVER", "ODBC Driver 17 for SQL Server")
    if not servidor:
        creds = os.path.expanduser("~/.claude/skills/sql-server/credentials.json")
        if not os.path.exists(creds):
            raise RuntimeError("defina EVASAO_SQL_SERVER (ambiente ou .env na raiz)")
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
    log.info("conectando em %s (%s)", servidor,
             "usuario SQL" if usuario else "autenticacao Windows")
    return pyodbc.connect(
        f"DRIVER={{{driver}}};SERVER={servidor};DATABASE=BANCO_APP;{auth}",
        timeout=1800,
    )


def extrair(con, data):
    """Roda a query 11. Com --data, troca o DT_CORTE_MANUAL; sem ela, o corte e
    ontem (o proprio SQL resolve)."""
    with open(QUERY, encoding="utf-8") as f:
        sql = f.read()
    if data:
        alvo = "CAST(NULL AS date)                  AS DT_CORTE_MANUAL"
        if sql.count(alvo) != 1:
            raise RuntimeError("linha do DT_CORTE_MANUAL nao encontrada na query 11")
        sql = sql.replace(alvo, f"CAST('{data}' AS date)              AS DT_CORTE_MANUAL")
    df = pd.read_sql(sql, con)
    for c in ("ALUNO", "CURSO", "END_MUNICIPIO"):
        if c in df.columns:
            df[c] = df[c].astype(str).str.strip()
    return df


def normalizar_curso(serie):
    """Os tres ultimos digitos do codigo. E o que o .pkl viu no treino.

    Os pares 2xxx/8xxx do hibrido sao o MESMO curso com credenciamento novo no
    MEC -- e o 8xxx e onde os calouros entram. Sem isto, a turma nova inteira
    cairia em OUTRO. Medido em 29/09/2026: 29 codigos viram 23, cobrindo 38,5%
    da base.
    """
    return serie.astype(str).str.strip().str[-3:]


def montar_x(d, art, corte):
    """Reproduz a engenharia do notebook do hibrido sobre a turma nova.

    As categorias e a lista de colunas vem do ARTEFATO -- recalcular sobre a
    turma nova daria colunas diferentes das que o modelo espera.

    O m1 do hibrido nao usa AVA (0 de 110 colunas). As colunas de AVA continuam
    sendo calculadas aqui; quem as descarta e o reindex pela colunas_modelo.
    """
    d = d.copy()
    ava = [c for c in d.columns if c.startswith("AVA_")]

    # ranks dentro da coorte pontuada (ha um unico DT_CORTE por execucao)
    for c in ava:
        d[c + "_RANK"] = d[c].rank(pct=True)
    d["RATIO_GAP_ATIVOS"] = d["AVA_MAIOR_GAP_DIAS"] / (d["AVA_DIAS_ATIVOS"] + 1)
    d["SESSOES_POR_DIA"] = d["AVA_QTD_SESSOES"] / (d["AVA_DIAS_ATIVOS"] + 1)
    d["SEM_AVA"] = d[ava].isna().all(axis=1).astype(int)

    # NORMALIZA O CURSO antes do agrupamento -- a diferenca para o EAD
    # NORMALIZA O CURSO antes do agrupamento -- a diferenca para o EAD.
    # Vale so para montar o X: a tabela guarda o codigo CRU (decisao de
    # 06/10/2026), e quem junta os pares 2xxx/8xxx na consulta e a API.
    d["CURSO"] = normalizar_curso(d["CURSO"])

    # agrupamento da cauda longa: categoria que o treino nao viu vira OUTRO,
    # para cair na mesma coluna *_OUTRO (o reindex sozinho a zeraria como
    # referencia, que e outra coisa).
    grupos = art["agrupamentos"][corte]
    for c in ("CURSO", "END_MUNICIPIO"):
        if c in d.columns and c in grupos:
            validos = set(grupos[c])
            d[c] = d[c].apply(lambda x: str(x) if (pd.notna(x) and str(x) in validos) else "OUTRO")

    cat = [c for c in d.columns if c in grupos]
    for c in cat:
        d[c] = d[c].fillna("NAO_INFORMADO").astype(str)
    X = pd.get_dummies(d, columns=cat)

    colunas = art["colunas_modelo"][corte]
    X = X.reindex(columns=colunas, fill_value=0)
    # numerico fica com o que veio (NaN inclusive): o RandomForest >= 1.4 trata
    # NaN nativamente, e foi assim que o modelo treinou.
    X = X.astype(float)

    modelo = art["modelos"][corte]
    assert modelo.n_features_in_ == X.shape[1], (modelo.n_features_in_, X.shape[1])
    return X


def classificar(d, proba, faixas, mot=None):
    """Faixas pela POSICAO na fila do dia, nunca pela probabilidade -- o modelo
    nao e calibrado. So os alunos em risco entram; o resto nao e gravado.

    Os motivos sao calculados sobre a coorte INTEIRA (e dela que sai o "pior
    que a turma") e so depois recortados para quem ficou na fila."""
    n = len(d)
    r = pd.DataFrame({"DT_CORTE": d["DT_CORTE"].values,
                      "ALUNO": d["ALUNO"].astype(str).values,
                      "CURSO": d["CURSO"].values, "PROB": proba})
    for c in COLS_MOTIVO:
        r[c] = mot[c].values if mot is not None else None
    r = r.sort_values("PROB", ascending=False, kind="mergesort").reset_index(drop=True)
    r["POSICAO"] = np.arange(1, n + 1)
    lim_alta = math.ceil(faixas["PCT_ALTA"] * n)
    lim_media = math.ceil(faixas["PCT_MEDIA"] * n)
    lim_critica = math.ceil(faixas["PCT_CRITICA_DA_ALTA"] * lim_alta)
    r["CHANCE_EVASAO"] = np.select(
        [r["POSICAO"] <= lim_critica, r["POSICAO"] <= lim_alta, r["POSICAO"] <= lim_media],
        ["CRITICA", "ALTA", "MEDIA"], default="")
    return r[r["CHANCE_EVASAO"] != ""]


# ---------------------------------------------------------------------------
# MOTIVOS -- "por que este aluno esta na fila"
# ---------------------------------------------------------------------------
# Tres textos curtos por aluno, gravados em MOTIVO/MOTIVO2/MOTIVO3. Mesmo
# formato das colunas que o presencial e o EAD ja tem, para a API unificada
# ler as tres modalidades sem tratar excecao.
#
# E DESCRITIVO, nao e a atribuicao exata do modelo. Uso interno da equipe de
# retencao -- nunca mostrar ao aluno (LGPD).
#
# Duas travas que fazem o texto nao mentir:
#
#   1. So entra sinal que o modelo DAQUELE CORTE realmente usa. O m1 do hibrido
#      tem zero colunas de AVA (110 colunas, nenhuma AVA_*), entao dizer "sem
#      acessar o AVA" no m1 explicaria com uma coisa que nao pesou na nota. O
#      filtro e a propria colunas_modelo do artefato.
#   2. A ordem sai de importancia da feature x quao extremo o aluno esta DENTRO
#      da coorte do dia. Sinal que o modelo quase nao usa nao vira motivo 1 so
#      porque disparou.
#
# Sem acento: o banco e latin-1 e a coluna e varchar(120), igual as do
# presencial ("notas baixas (media 3.2)", "reprovacoes no semestre anterior").

COLS_MOTIVO = ["MOTIVO", "MOTIVO2", "MOTIVO3"]
MOTIVO_TAM = 120          # varchar(120) na tabela
MOTIVO_PCT_MIN = 0.60     # abaixo deste percentil de risco o sinal nem e citado

# Sinais quase sinonimos. So UM de cada familia entra nos tres slots -- senao o
# m3 devolvia "sem acessar ha 69 dias", "nenhuma sessao" e "poucos dias de
# acesso" para o mesmo aluno, tres frases para o mesmo fato, e a equipe perdia
# os dois outros angulos.
FAMILIA = {
    "_SEM_AVA": "ava", "AVA_DIAS_DESDE_ULT_ACESSO": "ava", "AVA_SESSOES_RECENTES": "ava",
    "AVA_DIAS_ATIVOS_RECENTES": "ava", "AVA_DIAS_ATIVOS": "ava", "AVA_TEND_SESSOES": "ava",
    "NOTA_MEDIA": "notas", "NOTA_MINIMA": "notas",
    "TAXA_APROVACAO": "trimestre_ant", "DISC_REP_NOTA_ANT": "trimestre_ant",
    "DISC_REP_FREQ_ANT": "trimestre_ant",
    "HIST_TAXA_APROVACAO": "historico", "HIST_DISC_REPROVADAS": "historico",
    "CREDITOS": "carga", "DISC_MATRICULADAS_INICIO": "carga",
}


# col, direcao ('maior'/'menor' = pior), gatilho(valor, mediana)->bool,
# texto(valor, mediana)->str, exige(d)->mask ou None
#
# `exige` e uma condicao que depende de OUTRA coluna. So TAXA_APROVACAO usa:
# ver o cabecalho do conserto no topo desta secao.
#
# O texto leva a mediana da turma sempre que o numero sozinho nao se explica.
# CREDITOS e CARGA HORARIA, nao credito: mediana 160, ~40 por disciplina --
# escrever "carga baixa (100 creditos)" estaria errado no termo e ambiguo no
# numero, porque 100 parece alto para quem le "credito".
SINAIS = [
    ("AVA_DIAS_DESDE_ULT_ACESSO", "maior", lambda v, m: v >= 8,
     lambda v, m: "sem acessar o AVA ha %d dias" % v, None),
    ("AVA_SESSOES_RECENTES",      "menor", lambda v, m: v <= 2,
     lambda v, m: "nenhuma sessao no AVA nas ultimas semanas" if v == 0
                  else "poucas sessoes no AVA no periodo recente (%d, mediana %d)" % (v, m), None),
    ("AVA_DIAS_ATIVOS_RECENTES",  "menor", lambda v, m: v <= 2,
     lambda v, m: "poucos dias de acesso ao AVA no periodo recente (%d, mediana %d)" % (v, m), None),
    ("AVA_DIAS_ATIVOS",           "menor", lambda v, m: v <= 5,
     lambda v, m: "pouco acesso ao AVA no trimestre (%d dias, mediana %d)" % (v, m), None),
    ("AVA_TEND_SESSOES",          "menor", lambda v, m: v < 0,
     lambda v, m: "acesso ao AVA em queda", None),
    ("NOTA_MEDIA",                "menor", lambda v, m: v < 6.0,
     lambda v, m: "notas baixas (media %.1f, mediana da turma %.1f)" % (v, m), None),
    ("NOTA_MINIMA",               "menor", lambda v, m: v < 3.0,
     lambda v, m: "pelo menos uma nota muito baixa (%.1f)" % v, None),
    # So entra quando o trimestre anterior EXISTE na base. Sem isso a coluna e o
    # historico acumulado (COALESCE na query 11) e o texto mentiria o periodo --
    # e repetiria o mesmo numero que HIST_TAXA_APROVACAO ja diz.
    ("TAXA_APROVACAO",            "menor", lambda v, m: v < 0.7,
     lambda v, m: "aprovou %d%% das disciplinas do trimestre anterior" % round(100 * v),
     lambda d: d["DISC_CURSADAS_ANT"].notna() if "DISC_CURSADAS_ANT" in d.columns else None),
    ("DISC_REP_NOTA_ANT",         "maior", lambda v, m: v >= 1,
     lambda v, m: "reprovacoes por nota no trimestre anterior (%d)" % v, None),
    ("DISC_REP_FREQ_ANT",         "maior", lambda v, m: v >= 1,
     lambda v, m: "reprovacoes por falta no trimestre anterior (%d)" % v, None),
    ("HIST_TAXA_APROVACAO",       "menor", lambda v, m: v < 0.7,
     lambda v, m: "historico fraco: aprovou %d%% das disciplinas ate aqui" % round(100 * v), None),
    ("HIST_DISC_REPROVADAS",      "maior", lambda v, m: v >= 2,
     lambda v, m: "ja reprovou %d disciplinas no curso" % v, None),
    # 80% da mediana: abaixo disso e carga visivelmente menor que a da turma.
    # So o percentil nao basta -- a distribuicao e discreta (100/120/150/160/200)
    # e 150h contra 160h caia no percentil ruim sem ser "carga baixa".
    ("CREDITOS",                  "menor", lambda v, m: v > 0 and v <= 0.8 * m,
     lambda v, m: "carga horaria baixa (%dh contra %dh da turma)" % (v, m), None),
    ("DISC_MATRICULADAS_INICIO",  "menor", lambda v, m: v <= 2,
     lambda v, m: "poucas disciplinas matriculadas (%d, mediana %d)" % (v, m), None),
]


def _importancias(art, corte):
    """Importancia por coluna-base. As dummies de uma categorica e os _RANK de
    uma coluna de AVA somam na coluna de origem, senao a importancia fica
    diluida e o sinal parece mais fraco do que e."""
    modelo = art["modelos"][corte]
    acc = {}
    for nome, imp in zip(art["colunas_modelo"][corte], modelo.feature_importances_):
        base = nome[:-5] if nome.endswith("_RANK") else nome
        acc[base] = acc.get(base, 0.0) + float(imp)
    return acc


def motivos(d, art, corte):
    """DataFrame com MOTIVO, MOTIVO2 e MOTIVO3, no indice de `d`."""
    imp = _importancias(art, corte)
    usadas = {c[:-5] if c.endswith("_RANK") else c for c in art["colunas_modelo"][corte]}

    textos, pesos = {}, {}
    for col, direcao, gatilho, texto, exige in SINAIS:
        # trava 1: o modelo deste corte precisa usar a coluna
        if col not in usadas or col not in d.columns or imp.get(col, 0.0) <= 0:
            continue
        v = pd.to_numeric(d[col], errors="coerce")
        if v.notna().sum() == 0:
            continue
        med = v.median()
        # quao extremo o aluno esta dentro da coorte do dia, na direcao ruim
        pct = v.rank(pct=True)
        if direcao == "menor":
            pct = 1.0 - pct
        ok = v.notna() & (pct >= MOTIVO_PCT_MIN) & v.map(
            lambda x: bool(gatilho(x, med)) if pd.notna(x) else False)
        if exige is not None:
            m_extra = exige(d)
            if m_extra is None:          # a coluna de apoio nem veio na query
                continue
            ok = ok & m_extra.fillna(False)
        if not ok.any():
            continue
        textos[col] = v.where(ok).map(lambda x: texto(x, med) if pd.notna(x) else None)
        pesos[col] = (pct * imp[col]).where(ok)

    # o "sem AVA nenhum" nao sai de coluna: e a ausencia de todas elas
    ava = [c for c in d.columns if c.startswith("AVA_")]
    if ava and any(c in usadas for c in ava):
        sem = d[ava].isna().all(axis=1)
        if sem.any():
            peso_ava = max((imp.get(c, 0.0) for c in ava), default=0.0)
            textos["_SEM_AVA"] = pd.Series(np.where(sem, "sem registro de acesso ao AVA", None),
                                           index=d.index)
            pesos["_SEM_AVA"] = pd.Series(np.where(sem, peso_ava, np.nan), index=d.index)

    out = pd.DataFrame(index=d.index, columns=COLS_MOTIVO, dtype=object)
    if not pesos:
        log.warning("corte %s: nenhum sinal disponivel -- motivos sairao vazios", corte)
        return out

    P = pd.DataFrame(pesos)
    T = pd.DataFrame(textos)
    ordem = np.argsort(-P.fillna(-1.0).to_numpy(), axis=1)   # maior peso primeiro
    Tv, Pv = T.to_numpy(dtype=object), P.to_numpy(dtype=float)
    fam = [FAMILIA.get(c, c) for c in P.columns]
    for i in range(len(out)):
        vistas, k = set(), 0
        for j in ordem[i]:
            if k == 3:
                break
            if pd.isna(Pv[i, j]) or Tv[i, j] is None or fam[j] in vistas:
                continue
            vistas.add(fam[j])
            out.iat[i, k] = Tv[i, j][:MOTIVO_TAM]
            k += 1
    return out


def gravar(con, r, dia):
    """Apaga so o DT_CORTE do dia e regrava, na mesma transacao. A chave unica
    (DT_CORTE, ALUNO) impede duplicata se o dia rodar duas vezes."""
    cur = con.cursor()
    try:
        cur.execute(f"DELETE FROM {TABELA} WHERE DT_CORTE = ?", dia)
        apagadas = cur.rowcount
        cols = ["DT_CORTE", "ALUNO", "CHANCE_EVASAO", "CURSO"] + COLS_MOTIVO
        cur.fast_executemany = True
        cur.executemany(
            f"INSERT INTO {TABELA} ({', '.join(cols)}) VALUES ({', '.join('?' * len(cols))})",
            list(r[cols].astype(object).where(pd.notna(r[cols]), None)
                 .itertuples(index=False, name=None)),
        )
        con.commit()
        if apagadas:
            log.info("regravado: %d linhas antigas de %s foram apagadas", apagadas, dia)
    except Exception:
        con.rollback()
        raise
    finally:
        cur.close()


def main():
    ap = argparse.ArgumentParser(description="Pontua o hibrido e grava a fila do dia.")
    ap.add_argument("--data", help="corte a reprocessar (AAAA-MM-DD). Padrao: ontem.")
    ap.add_argument("--gravar", action="store_true", help="grava na tabela (sem isto, so mostra)")
    a = ap.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    art = joblib.load(ARTEFATO)
    if "colunas_modelo" not in art:
        raise RuntimeError("o .pkl nao tem colunas_modelo -- retreine o notebook do hibrido")
    faixas = art["faixas"]

    with conexao() as con:
        d = extrair(con, a.data)
        if d.empty:
            log.info("query nao devolveu alunos (corte fora de trimestre letivo?) -- nada a pontuar")
            return 0

        dia = pd.to_datetime(d["DT_CORTE"].iloc[0]).date()
        corte = d["MODELO_CORTE"].iloc[0]
        log.info("corte %s | %d alunos | MODELO_CORTE=%s | trimestre %s/%s | dia %s do trimestre",
                 dia, len(d), corte, d["ANO_OBS"].iloc[0], d["PERIODO_OBS"].iloc[0],
                 d["DIAS_DE_TRIMESTRE"].iloc[0])

        if pd.isna(corte):
            log.info("MODELO_CORTE nulo -- nada a pontuar")
            return 0
        corte = int(corte)
        if d["DT_CORTE"].nunique() != 1 or d["ALUNO"].duplicated().any():
            raise RuntimeError("query devolveu mais de um corte ou aluno repetido")
        if len(d) < MIN_ALUNOS:
            raise RuntimeError(f"so {len(d)} alunos (minimo {MIN_ALUNOS}) -- nao gravo fila incompleta")

        proba = art["modelos"][corte].predict_proba(montar_x(d, art, corte))[:, 1]
        r = classificar(d, proba, faixas, motivos(d, art, corte))
        log.info("fila: %s", r["CHANCE_EVASAO"].value_counts().to_dict())
        com = r["MOTIVO"].notna().sum()
        log.info("motivos: %d de %d linhas com ao menos um (%.1f%%); media de %.2f por aluno",
                 com, len(r), 100.0 * com / max(len(r), 1),
                 r[COLS_MOTIVO].notna().sum(axis=1).mean())

        if a.gravar:
            gravar(con, r, dia)
            log.info("gravado em %s: %d linhas para %s", TABELA, len(r), dia)
        else:
            log.info("simulacao (sem --gravar): %d linhas seriam gravadas", len(r))
            print(r.head(10).to_string(index=False))

    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        log.exception("falhou")
        sys.exit(1)
