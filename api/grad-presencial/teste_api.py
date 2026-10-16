"""
Teste da API contra o banco real (somente leitura).

    python teste_api.py        (dentro de api/grad-presencial/)

Cada resposta da API e conferida com uma contagem feita direto no SQL, na
fila mais recente. Nao depende de numero fixo: vale para qualquer dia gravado.
"""

import os
import sys

from fastapi.testclient import TestClient

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import app as api  # noqa: E402

os.environ.setdefault("EVASAO_API_ABERTA", "1")   # a bateria roda sem token; o bloco do token testa o resto
cli = TestClient(api.app)
falhas = 0


def confere(nome, cond, detalhe=""):
    global falhas
    print(f"  {'OK  ' if cond else 'FALHA'} {nome}" + (f"  ({detalhe})" if detalhe else ""))
    falhas += not cond


def sql_n(onde, *params):
    return api.consultar(
        f"SELECT COUNT(*) AS n FROM {api.TABELA} r LEFT JOIN {api.CURSOS} c ON c.CURSO = r.CURSO "
        f"WHERE r.DT_CORTE = ? {onde}", dia, *params)[0]["n"]


def todos(params):
    """Percorre todas as paginas."""
    out, pagina = [], 1
    while True:
        r = cli.get("/alunos", params={**params, "pagina": pagina, "limite": 50}).json()
        out += r["alunos"]
        if len(out) >= r["total"] or not r["alunos"]:
            return out, r["total"]
        pagina += 1


print("\n/saude")
r = cli.get("/saude")
confere("responde 200", r.status_code == 200, r.text[:80])
dia = api.resolver_data(None)
confere("ultima fila = MAX(DT_CORTE) da tabela", r.json()["ultima_fila"] == dia.isoformat(), dia.isoformat())
atualizada = dia >= api.fila_esperada()
confere("fila_atualizada coerente com a data", r.json()["fila_atualizada"] == atualizada,
        f"esperada {r.json()['fila_esperada']}, atraso {r.json()['dias_de_atraso']} dia(s)")
confere("/alunos repete o aviso de atraso",
        cli.get("/alunos", params={"limite": 1}).json()["fila_atualizada"] == atualizada)

print(f"\n/alunos?curso=engenharia&faixa=CRITICA  (fila de {dia})")
lst, total = todos({"curso": "engenharia", "faixa": "CRITICA"})
esperado = sql_n("AND r.CHANCE_EVASAO = 'CRITICA' AND c.NOME LIKE '%ENGENHARIA%'")
confere("total igual ao SQL direto", total == esperado == len(lst), f"API {total}, SQL {esperado}")
confere("todos sao de engenharia", all("ENGENHARIA" in (a["nome_curso"] or "").upper() for a in lst))
confere("todos sao CRITICA", all(a["chance_evasao"] == "CRITICA" for a in lst))
confere("em ordem de fila, sem repetir", [a["posicao"] for a in lst] == sorted({a["posicao"] for a in lst}))
for a in lst[:3]:
    print(f"       pos {a['posicao']:>4}  RA {a['aluno']}  {a['nome_curso']}")

print("\nbusca por curso")
n1 = cli.get("/alunos", params={"curso": "ENGENHARIA", "faixa": "CRITICA"}).json()["total"]
n2 = cli.get("/alunos", params={"curso": "Engenharia", "faixa": "CRITICA"}).json()["total"]
confere("maiuscula/minuscula nao importa", n1 == n2 == total, f"{n1} / {n2} / {total}")
n_ac = cli.get("/alunos", params={"curso": "mecanica", "faixa": "CRITICA"}).json()
n_sql = sql_n("AND r.CHANCE_EVASAO = 'CRITICA' AND c.NOME LIKE N'%MECÂNICA%'")
confere("acento nao importa ('mecanica' acha MECANICA)", n_ac["total"] == n_sql, f"{n_ac['total']} / SQL {n_sql}")
cod = n_ac["alunos"][0]["curso"] if n_ac["alunos"] else "258"
n_cod = cli.get("/alunos", params={"curso": cod, "faixa": "CRITICA"}).json()["total"]
confere(f"por codigo (curso={cod}) = por nome", n_cod == n_sql, f"{n_cod}")

print("\nfaixas e posicao")
crit, n_crit = todos({"faixa": "CRITICA"})
confere("CRITICA = posicoes 1..N sem buraco", [a["posicao"] for a in crit] == list(range(1, n_crit + 1)), f"N={n_crit}")
duas = cli.get("/alunos", params=[("faixa", "CRITICA"), ("faixa", "ALTA"), ("limite", 1)]).json()["total"]
confere("varias faixas de uma vez", duas == sql_n("AND r.CHANCE_EVASAO IN ('CRITICA','ALTA')"), f"{duas}")

print("\npaginacao")
p1 = cli.get("/alunos", params={"curso": "engenharia", "faixa": "CRITICA", "limite": 10, "pagina": 1}).json()
p2 = cli.get("/alunos", params={"curso": "engenharia", "faixa": "CRITICA", "limite": 10, "pagina": 2}).json()
confere("paginas nao se repetem", not {a["aluno"] for a in p1["alunos"]} & {a["aluno"] for a in p2["alunos"]})
fim = cli.get("/alunos", params={"curso": "engenharia", "faixa": "CRITICA", "pagina": 999}).json()
confere("pagina alem do fim: vazia, total certo", fim["alunos"] == [] and fim["total"] == total)

print("\nentrada digitada a mao")
n_min = cli.get("/alunos", params={"curso": "engenharia", "faixa": "critica"}).json()["total"]
confere("faixa em minusculo ('critica') = CRITICA", n_min == total, f"{n_min}")
n_pct = cli.get("/alunos", params={"curso": "%"}).json()["total"]
confere("'%' e texto, nao curinga (nenhum curso tem % no nome)", n_pct == 0, f"{n_pct}")
n_und = cli.get("/alunos", params={"curso": "_"}).json()["total"]
confere("'_' e texto, nao curinga", n_und == 0, f"{n_und}")
n_esp = cli.get("/alunos", params={"curso": "   ", "limite": 1}).json()["total"]
confere("curso so com espacos = sem filtro", n_esp == sql_n(""), f"{n_esp}")

print("\nerros")
confere("faixa invalida -> 422", cli.get("/alunos", params={"faixa": "ALTISSIMA"}).status_code == 422)
confere("data sem fila -> 404", cli.get("/alunos", params={"data": "2020-01-01"}).status_code == 404)
confere("curso inexistente -> lista vazia", cli.get("/alunos", params={"curso": "astronauta"}).json()["total"] == 0)

print("\n/resumo e /datas")
res = cli.get("/resumo", params={"curso": "engenharia"}).json()
confere("resumo: soma da CRITICA = /alunos", sum(x["critica"] for x in res) == total, f"{len(res)} cursos")
dts = cli.get("/datas").json()
d0 = dts[0]
confere("datas: mais recente primeiro", d0["dt_corte"] == dia.isoformat())
confere("datas: faixas somam o total", d0["critica"] + d0["alta"] + d0["media"] == d0["total"] == sql_n(""),
        f"{d0['critica']}/{d0['alta']}/{d0['media']}")

print("\n/alunos/{ra}")
ra = crit[0]["aluno"]
h = cli.get(f"/alunos/{ra}").json()
confere("1o da fila: CRITICA, posicao 1", h["faixa_ultima_fila"] == "CRITICA" and h["historico"][0]["posicao"] == 1
        and h["historico"][0]["dt_corte"] == dia.isoformat(), f"RA {ra}")
print(f"       leitura: {h['leitura']}")
fora = api.consultar(f"SELECT TOP 1 a.ALUNO FROM {api.ALUNOS} a WHERE a.COL_SITUACAO = 'Ativo' AND NOT EXISTS "
                     f"(SELECT 1 FROM {api.TABELA} r WHERE r.ALUNO = a.ALUNO)")[0]["ALUNO"]
hf = cli.get(f"/alunos/{fora}")
confere("RA que existe, fora da fila -> 200, sem faixa", hf.status_code == 200
        and hf.json()["faixa_ultima_fila"] is None and hf.json()["historico"] == [], f"RA {fora}")
print(f"       leitura: {hf.json()['leitura']}")
confere("RA inexistente -> 404 (nao confunde com sem risco)", cli.get("/alunos/XYZ0000").status_code == 404)
com_zero = api.consultar(f"SELECT TOP 1 ALUNO FROM {api.TABELA} WHERE DT_CORTE = ? AND ALUNO LIKE '0%'", dia)
if com_zero:
    orig = com_zero[0]["ALUNO"]
    hz = cli.get(f"/alunos/{orig.lstrip('0')}")
    confere("RA sem o zero a esquerda acha o aluno", hz.status_code == 200 and hz.json()["aluno"] == orig
            and hz.json()["faixa_ultima_fila"] is not None, f"{orig.lstrip('0')} -> {orig}")
inat = api.consultar(f"SELECT TOP 1 r.ALUNO, a.COL_SITUACAO FROM {api.TABELA} r JOIN {api.ALUNOS} a ON a.ALUNO = r.ALUNO "
                     f"WHERE r.DT_CORTE = ? AND a.COL_SITUACAO <> 'Ativo'", dia)
if inat:
    hi = cli.get(f"/alunos/{inat[0]['ALUNO']}").json()
    confere("na fila mas nao mais Ativo -> leitura avisa",
            hi["sit_aluno"] == inat[0]["COL_SITUACAO"] and inat[0]["COL_SITUACAO"] in hi["leitura"], hi["leitura"])

print("\nsem token configurado: recusa tudo")
os.environ.pop("EVASAO_API_TOKEN", None)
os.environ["EVASAO_API_ABERTA"] = ""
confere("sem token e sem EVASAO_API_ABERTA -> 503", cli.get("/saude").status_code == 503)
confere("dados tambem recusados", cli.get("/alunos").status_code == 503)
os.environ["EVASAO_API_ABERTA"] = "1"

print("\ntoken (EVASAO_API_TOKEN)")
os.environ["EVASAO_API_TOKEN"] = "teste123"
confere("sem token -> 401", cli.get("/saude").status_code == 401)
confere("token errado -> 401", cli.get("/saude", headers={"Authorization": "Bearer x"}).status_code == 401)
confere("token certo -> 200", cli.get("/saude", headers={"Authorization": "Bearer teste123"}).status_code == 200)
confere("dados protegidos: /alunos sem token -> 401", cli.get("/alunos").status_code == 401)
esquema = cli.get("/openapi.json").json().get("components", {}).get("securitySchemes", {})
confere("/docs tem botao Authorize (esquema Bearer)", any(s.get("scheme") == "bearer" for s in esquema.values()))
del os.environ["EVASAO_API_TOKEN"]

print(f"\n{'TUDO OK' if not falhas else f'{falhas} FALHA(S)'}")
sys.exit(1 if falhas else 0)
