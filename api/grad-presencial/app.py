"""
API de consulta -- risco de evasao da Graduacao Presencial.

    job diario (job/pontuar_presencial.py)  ->  TABELA_RISCO_PRESENCIAL  ->  esta API

So LE a tabela. Nao roda modelo, nao grava nada: quem pontua e o job. A conta
do banco usada aqui precisa apenas de SELECT em
BANCO_APP.dbo.TABELA_RISCO_PRESENCIAL, BANCO_ERP.dbo.TABELA_CURSOS e
BANCO_ERP.dbo.TABELA_ALUNOS.

Rodar:
    uvicorn app:app --host 0.0.0.0 --port 8000        (dentro de api/grad-presencial/)
    documentacao interativa em http://localhost:8000/docs

Exemplo -- engenharias com chance critica, na fila mais recente:
    GET /alunos?curso=engenharia&faixa=CRITICA

Variaveis de ambiente (as mesmas do job):
    EVASAO_SQL_SERVER, EVASAO_SQL_DRIVER, EVASAO_SQL_USER, EVASAO_SQL_PASSWORD
    EVASAO_API_TOKEN   se definida, toda chamada exige o cabecalho
                       "Authorization: Bearer <token>" (a tabela tem RA de aluno)
"""

import datetime as dt
import json
import os
import secrets
from enum import Enum

import pyodbc
from fastapi import Depends, FastAPI, HTTPException, Query
from fastapi.responses import JSONResponse
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel

TABELA = "BANCO_APP.dbo.TABELA_RISCO_PRESENCIAL"
CURSOS = "BANCO_ERP.dbo.TABELA_CURSOS"
ALUNOS = "BANCO_ERP.dbo.TABELA_ALUNOS"

# Calendario do job no integrador: em quais dias ele roda (ISO: 1 = segunda ...
# 7 = domingo) e ate que hora ja terminou. Cada execucao grava DT_CORTE = vespera.
# Padrao: segunda e quinta, pronto as 8h (roda ~06:30).
DIAS_JOB = os.environ.get("EVASAO_DIAS_JOB", "1,4")
HORA_JOB = int(os.environ.get("EVASAO_HORA_JOB", "8"))


class Faixa(str, Enum):
    CRITICA = "CRITICA"
    ALTA = "ALTA"
    MEDIA = "MEDIA"


class Aluno(BaseModel):
    dt_corte: dt.date
    posicao: int          # posicao na fila do dia (1 = maior risco), contando TODOS os cursos
    aluno: str
    chance_evasao: Faixa
    curso: str | None
    nome_curso: str | None


class PaginaAlunos(BaseModel):
    dt_corte: dt.date
    fila_atualizada: bool  # False = nao e a fila mais recente esperada (job atrasado ou data antiga pedida)
    total: int            # quantos atendem o filtro (sem paginacao)
    pagina: int
    limite: int
    alunos: list[Aluno]


class HistoricoAluno(BaseModel):
    aluno: str
    sit_aluno: str | None     # situacao ATUAL no ERP academico (Ativo, Cancelado...) -- pode ter mudado depois da fila
    curso: str | None
    nome_curso: str | None
    ultima_fila: dt.date
    fila_atualizada: bool
    faixa_ultima_fila: Faixa | None   # None = fora das faixas na fila mais recente
    leitura: str              # o resultado em uma frase, para quem le (ou para o assistente)
    historico: list[Aluno]    # filas recentes em que apareceu, da mais recente para a mais antiga


class ResumoCurso(BaseModel):
    curso: str | None
    nome_curso: str | None
    critica: int
    alta: int
    media: int
    total: int


class DataCorte(BaseModel):
    dt_corte: dt.date
    critica: int
    alta: int
    media: int
    total: int


# ---------------------------------------------------------------- banco

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
    return pyodbc.connect(
        f"DRIVER={{{driver}}};SERVER={servidor};DATABASE=BANCO_APP;{auth}",
        timeout=15, readonly=True)


def consultar(sql, *params):
    try:
        with conexao() as con:
            cur = con.cursor()
            cur.execute(sql, *params)
            cols = [c[0] for c in cur.description]
            return [dict(zip(cols, r)) for r in cur.fetchall()]
    except pyodbc.Error as e:
        raise HTTPException(503, f"banco indisponivel: {e.args[-1] if e.args else e}")


def resolver_data(data):
    """Sem data = fila mais recente. Com data = precisa existir na tabela."""
    if data is None:
        r = consultar(f"SELECT MAX(DT_CORTE) AS d FROM {TABELA}")
        if r[0]["d"] is None:
            raise HTTPException(404, "tabela vazia -- o job ainda nao gravou nenhuma fila")
        return r[0]["d"]
    r = consultar(f"SELECT TOP 1 DT_CORTE AS d FROM {TABELA} WHERE DT_CORTE = ?", data)
    if not r:
        raise HTTPException(404, f"nao ha fila gravada para {data.isoformat()} -- veja /datas")
    return data


def fila_esperada():
    """DT_CORTE que o job ja deveria ter gravado agora: a vespera da execucao
    mais recente do calendario (a de hoje so conta depois de HORA_JOB)."""
    dias = {int(d) for d in DIAS_JOB.split(",") if d.strip().isdigit() and 1 <= int(d) <= 7} or set(range(1, 8))
    agora = dt.datetime.now()
    for k in range(8):
        d = agora.date() - dt.timedelta(days=k)
        if d.isoweekday() in dias and not (k == 0 and agora.hour < HORA_JOB):
            return d - dt.timedelta(days=1)
    return agora.date() - dt.timedelta(days=1)


def filtro_curso(curso):
    """So digitos = codigo exato (ex. 258). Texto = trecho do nome, sem ligar para
    acento nem maiuscula (o collation do banco e CI_AI): 'engenharia', 'mecanica'."""
    if not curso or not curso.strip():
        return "", []
    curso = curso.strip()
    if curso.isdigit():
        return " AND f.CURSO = ?", [curso]
    # % _ [ digitados valem como texto, nao como curinga do LIKE
    for c in ("\\", "%", "_", "["):
        curso = curso.replace(c, "\\" + c)
    return " AND f.NOME_CURSO LIKE ? ESCAPE '\\'", [f"%{curso}%"]


def normalizar_faixas(faixa):
    """Aceita CRITICA, critica, Critica... Valor fora das tres faixas = 422."""
    if not faixa:
        return []
    saida = []
    for f in faixa:
        try:
            saida.append(Faixa(f.strip().upper()))
        except ValueError:
            raise HTTPException(422, f"faixa invalida: {f!r} -- use CRITICA, ALTA ou MEDIA")
    return saida


# A posicao sai do ID: o job insere a fila do dia em ordem, do maior risco para
# o menor. E calculada ANTES do filtro, entao e a posicao na fila geral.
FILA = f"""
WITH fila AS (
    SELECT r.DT_CORTE, r.ALUNO, r.CHANCE_EVASAO, r.CURSO, c.NOME AS NOME_CURSO,
           ROW_NUMBER() OVER (ORDER BY r.ID) AS POSICAO
    FROM {TABELA} r
    LEFT JOIN {CURSOS} c ON c.CURSO = r.CURSO
    WHERE r.DT_CORTE = ?
)
"""


# ---------------------------------------------------------------- app

# HTTPBearer faz o /docs mostrar o botao "Authorize" para colar o token. A
# pagina /docs em si fica aberta (so descreve as rotas); os dados, nao.
bearer = HTTPBearer(auto_error=False, description="valor de EVASAO_API_TOKEN")


def exigir_token(cred: HTTPAuthorizationCredentials | None = Depends(bearer)):
    token = os.environ.get("EVASAO_API_TOKEN")
    if not token:
        # sem token a API recusa tudo -- abrir so de proposito (teste local)
        if os.environ.get("EVASAO_API_ABERTA") == "1":
            return
        raise HTTPException(503, "API sem token configurado -- defina EVASAO_API_TOKEN")
    if not (cred and secrets.compare_digest(cred.credentials, token)):
        raise HTTPException(401, "token ausente ou invalido", headers={"WWW-Authenticate": "Bearer"})


class JSONUtf8(JSONResponse):
    # charset explicito: sem ele, clientes antigos (PowerShell 5.1) leem
    # "QUIMICA" com acento como "QUÃMICA"
    media_type = "application/json; charset=utf-8"


app = FastAPI(
    default_response_class=JSONUtf8,
    title="Risco de evasao -- Graduacao Presencial",
    description="Consulta a fila diaria gravada pelo job em TABELA_RISCO_PRESENCIAL. "
                "Faixas pela posicao na fila do dia: CRITICA = 20% do topo da ALTA (~2% da base), "
                "ALTA = ate 10%, MEDIA = ate 20%. Aluno fora da tabela = sem risco.",
    version="1.0",
    dependencies=[Depends(exigir_token)],
)


@app.get("/saude", summary="O banco responde e se a fila mais recente e a de hoje")
def saude():
    r = consultar(f"SELECT MAX(DT_CORTE) AS ultima, COUNT(DISTINCT DT_CORTE) AS dias FROM {TABELA}")[0]
    esperada = fila_esperada()
    atraso = (esperada - r["ultima"]).days if r["ultima"] else None
    return {"status": "ok", "ultima_fila": r["ultima"], "fila_esperada": esperada,
            "fila_atualizada": atraso is not None and atraso <= 0,
            "dias_de_atraso": max(atraso, 0) if atraso is not None else None,
            "dias_gravados": r["dias"]}


@app.get("/alunos", response_model=PaginaAlunos, summary="Alunos em risco, com filtro por curso e faixa")
def alunos(
    curso: str | None = Query(None, description="codigo (258) ou trecho do nome (engenharia, direito)"),
    faixa: list[str] | None = Query(None, description="uma ou mais: CRITICA, ALTA, MEDIA (maiuscula ou nao)"),
    data: dt.date | None = Query(None, description="DT_CORTE (padrao: a fila mais recente)"),
    pagina: int = Query(1, ge=1),
    limite: int = Query(100, ge=1, le=1000),
):
    faixa = normalizar_faixas(faixa)
    dia = resolver_data(data)
    onde, params = filtro_curso(curso)
    if faixa:
        onde += f" AND f.CHANCE_EVASAO IN ({','.join('?' * len(faixa))})"
        params += [f.value for f in faixa]
    sql = FILA + f"""
    SELECT f.*, COUNT(*) OVER () AS TOTAL
    FROM fila f
    WHERE 1 = 1 {onde}
    ORDER BY f.POSICAO
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    """
    rows = consultar(sql, dia, *params, (pagina - 1) * limite, limite)
    total = rows[0]["TOTAL"] if rows else 0
    if not rows and pagina > 1:
        # pagina alem do fim: devolve vazio, mas com o total certo
        total = consultar(FILA + f"SELECT COUNT(*) AS n FROM fila f WHERE 1 = 1 {onde}", dia, *params)[0]["n"]
    return PaginaAlunos(
        dt_corte=dia, fila_atualizada=dia >= fila_esperada(), total=total, pagina=pagina, limite=limite,
        alunos=[Aluno(dt_corte=r["DT_CORTE"], posicao=r["POSICAO"], aluno=r["ALUNO"],
                      chance_evasao=r["CHANCE_EVASAO"], curso=r["CURSO"], nome_curso=r["NOME_CURSO"])
                for r in rows])


@app.get("/alunos/{ra}", response_model=HistoricoAluno,
         summary="Situacao de um aluno na fila mais recente e nas anteriores")
def aluno(ra: str, dias: int = Query(30, ge=1, le=366, description="quantas filas mais recentes olhar")):
    ra = ra.strip()
    # O ERP academico grava o RA com 7 ou 8 digitos e zero a esquerda (0527810). Quem copia
    # de planilha costuma perder o zero (527810) ou trazer ponto/traco: tenta tambem
    # essas formas. Preferencia: exatamente como veio, depois so digitos, 7 e 8 casas.
    candidatos = [ra]
    digitos = "".join(ch for ch in ra if ch not in " .-")
    if digitos.isascii() and digitos.isdigit():
        for c in (digitos, digitos.zfill(7), digitos.zfill(8)):
            if c not in candidatos:
                candidatos.append(c)
    cad = consultar(f"""
        SELECT a.ALUNO, a.COL_SITUACAO, a.CURSO, c.NOME AS NOME_CURSO
        FROM {ALUNOS} a LEFT JOIN {CURSOS} c ON c.CURSO = a.CURSO
        WHERE a.ALUNO IN ({','.join('?' * len(candidatos))})""", *candidatos)
    if not cad:
        raise HTTPException(404, f"RA {ra} nao existe no ERP academico")
    cad = sorted(cad, key=lambda r: candidatos.index(r["ALUNO"]))[0]
    ra = cad["ALUNO"]   # o RA como o ERP academico grava -- e o que esta na fila
    ultima = resolver_data(None)
    sql = f"""
    WITH fila AS (
        SELECT r.DT_CORTE, r.ALUNO, r.CHANCE_EVASAO, r.CURSO, c.NOME AS NOME_CURSO,
               ROW_NUMBER() OVER (PARTITION BY r.DT_CORTE ORDER BY r.ID) AS POSICAO
        FROM {TABELA} r
        LEFT JOIN {CURSOS} c ON c.CURSO = r.CURSO
        WHERE r.DT_CORTE IN (SELECT DISTINCT TOP (?) DT_CORTE FROM {TABELA} ORDER BY DT_CORTE DESC)
    )
    SELECT * FROM fila WHERE ALUNO = ? ORDER BY DT_CORTE DESC
    """
    hist = [Aluno(dt_corte=r["DT_CORTE"], posicao=r["POSICAO"], aluno=r["ALUNO"],
                  chance_evasao=r["CHANCE_EVASAO"], curso=r["CURSO"], nome_curso=r["NOME_CURSO"])
            for r in consultar(sql, dias, ra)]
    atual = hist[0] if hist and hist[0].dt_corte == ultima else None
    if atual:
        leitura = f"{atual.chance_evasao.value} na fila de {ultima.isoformat()}, posicao {atual.posicao}."
    else:
        # o job so grava as tres faixas: fora da tabela = os ~80% de menor risco,
        # OU aluno que o job nem pontua (fora da graduacao presencial)
        leitura = (f"Fora das faixas de risco na fila de {ultima.isoformat()}: ou esta entre os ~80% "
                   f"de menor risco, ou nao e aluno da graduacao presencial pontuado pelo job.")
    if cad["COL_SITUACAO"] and cad["COL_SITUACAO"] != "Ativo":
        leitura += f" Situacao atual no ERP academico: {cad['COL_SITUACAO']}."
    atualizada = ultima >= fila_esperada()
    if not atualizada:
        leitura += f" ATENCAO: fila desatualizada -- o job nao gravou a mais recente (esperada: {fila_esperada().isoformat()})."
    return HistoricoAluno(
        aluno=cad["ALUNO"], sit_aluno=cad["COL_SITUACAO"], curso=cad["CURSO"], nome_curso=cad["NOME_CURSO"],
        ultima_fila=ultima, fila_atualizada=atualizada,
        faixa_ultima_fila=atual.chance_evasao if atual else None,
        leitura=leitura, historico=hist)


@app.get("/resumo", response_model=list[ResumoCurso], summary="Contagem por curso e faixa")
def resumo(
    curso: str | None = Query(None, description="codigo ou trecho do nome"),
    data: dt.date | None = Query(None, description="DT_CORTE (padrao: a fila mais recente)"),
):
    dia = resolver_data(data)
    onde, params = filtro_curso(curso)
    sql = FILA + f"""
    SELECT f.CURSO, f.NOME_CURSO,
           SUM(CASE WHEN f.CHANCE_EVASAO = 'CRITICA' THEN 1 ELSE 0 END) AS critica,
           SUM(CASE WHEN f.CHANCE_EVASAO = 'ALTA'    THEN 1 ELSE 0 END) AS alta,
           SUM(CASE WHEN f.CHANCE_EVASAO = 'MEDIA'   THEN 1 ELSE 0 END) AS media,
           COUNT(*) AS total
    FROM fila f
    WHERE 1 = 1 {onde}
    GROUP BY f.CURSO, f.NOME_CURSO
    ORDER BY critica DESC, total DESC
    """
    return [ResumoCurso(curso=r["CURSO"], nome_curso=r["NOME_CURSO"], critica=r["critica"],
                        alta=r["alta"], media=r["media"], total=r["total"])
            for r in consultar(sql, dia, *params)]


@app.get("/datas", response_model=list[DataCorte], summary="Filas gravadas, da mais recente para a mais antiga")
def datas(limite: int = Query(60, ge=1, le=366)):
    sql = f"""
    SELECT TOP (?) DT_CORTE,
           SUM(CASE WHEN CHANCE_EVASAO = 'CRITICA' THEN 1 ELSE 0 END) AS critica,
           SUM(CASE WHEN CHANCE_EVASAO = 'ALTA'    THEN 1 ELSE 0 END) AS alta,
           SUM(CASE WHEN CHANCE_EVASAO = 'MEDIA'   THEN 1 ELSE 0 END) AS media,
           COUNT(*) AS total
    FROM {TABELA}
    GROUP BY DT_CORTE
    ORDER BY DT_CORTE DESC
    """
    return [DataCorte(dt_corte=r["DT_CORTE"], critica=r["critica"], alta=r["alta"],
                      media=r["media"], total=r["total"])
            for r in consultar(sql, limite)]
