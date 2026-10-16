# API de consulta — risco de evasão, Graduação Presencial

Consulta a fila diária gravada pelo job em `BANCO_APP.dbo.TABELA_RISCO_PRESENCIAL`.

```
integrador (seg e qui, 06:30) → job/pontuar_presencial.py → TABELA_RISCO_PRESENCIAL → esta API
```

**A API só lê.** Não roda modelo nem grava nada: quem pontua é o job. Se o job
falhar, a API continua servindo a última fila gravada — mas avisa: `/saude`,
`/alunos` e `/alunos/{ra}` trazem `fila_atualizada`. Ela é `false` quando a fila mais
recente não é a da última execução do job, que roda **segunda e quinta** às ~06:30 e
grava `DT_CORTE` = véspera (até as 8h do dia de execução, a anterior ainda vale).
O calendário é configurável: `EVASAO_DIAS_JOB` (padrão `1,4`, ISO) e `EVASAO_HORA_JOB`
(padrão `8`). **Quem consome a API — um assistente, por exemplo — tem que conferir
esse campo antes de tratar a fila como a vigente.**

## Endpoints

| Rota | O que devolve |
|---|---|
| `GET /alunos` | alunos em risco, filtrando por `curso`, `faixa` (uma ou mais) e `data` |
| `GET /alunos/{ra}` | a situação do aluno na fila mais recente, a situação atual no ERP academico e as filas recentes em que ele apareceu |
| `GET /resumo` | contagem por curso e faixa |
| `GET /datas` | filas gravadas, com o total de cada faixa |
| `GET /saude` | o banco responde, qual é a fila mais recente e se ela está atrasada |
| `GET /docs` | documentação interativa (dá para testar pelo navegador) |

Sem `data`, tudo usa a **fila mais recente**.

`curso` aceita o **código** (`258`) ou um **trecho do nome** (`engenharia`, `mecanica`,
`direito`). Acento e maiúscula não importam — o banco é `Latin1_General_CI_AI`.
`faixa` também aceita minúscula (`critica` = `CRITICA`).

### Exemplos

```
GET /alunos?curso=engenharia&faixa=CRITICA           engenharias com chance crítica
GET /alunos?curso=258&faixa=CRITICA&faixa=ALTA        Eng. Mecânica, crítica + alta
GET /alunos?faixa=CRITICA&data=2026-09-23             a CRITICA inteira de um dia
GET /resumo?curso=engenharia                          quantos por curso e faixa
GET /alunos/9000001                                   histórico de um aluno
```

Resposta de `/alunos?curso=engenharia&faixa=CRITICA` (fila de 23/09/2026):

```json
{
  "dt_corte": "2026-09-23",
  "total": 36,
  "pagina": 1,
  "limite": 100,
  "alunos": [
    {"dt_corte": "2026-09-23", "posicao": 11, "aluno": "9000003",
     "chance_evasao": "CRITICA", "curso": "215", "nome_curso": "BACHARELADO EM ENGENHARIA QUÍMICA"},
    ...
  ]
}
```

- `posicao` é a posição na **fila geral do dia** (todos os cursos), não dentro do
  filtro: o 1º de engenharia é o 11º da escola.
- `total` é quantos atendem o filtro; a lista vem paginada (`pagina`, `limite` até 1.000).
- Aluno que não aparece = **sem risco** naquele dia (o job só grava as três faixas).

### `/alunos/{ra}` — três respostas diferentes, sem ambiguidade

| Caso | Resposta |
|---|---|
| RA não existe no ERP academico | **404** |
| existe, fora das faixas na fila mais recente | 200, `faixa_ultima_fila: null` |
| está numa faixa | 200, `faixa_ultima_fila: "CRITICA"` (ou `ALTA`, `MEDIA`) |

"Fora das faixas" cobre dois grupos que a tabela não separa: os ~80% de menor risco
**e** quem o job nem pontua (outra modalidade, curso encerrado). O campo `leitura` diz
isso em uma frase. `sit_aluno` é a situação **atual** no ERP academico: um aluno pode estar
na fila de ontem e já ter cancelado hoje (em 25/09/2026, 11 dos 1.886 da fila de 23/09
já não estavam `Ativo`).

```json
{
  "aluno": "9000001", "sit_aluno": "Ativo", "curso": "...", "nome_curso": "...",
  "ultima_fila": "2026-09-23", "fila_atualizada": false, "faixa_ultima_fila": "CRITICA",
  "leitura": "CRITICA na fila de 2026-09-23, posicao 1. ATENCAO: fila desatualizada -- o job nao gravou a mais recente (esperada: 2026-09-27).",
  "historico": [{"dt_corte": "2026-09-23", "posicao": 1, "chance_evasao": "CRITICA", ...}]
}
```

### Faixas

Pela posição na fila do dia, não pela probabilidade (o modelo não é calibrado):

| Faixa | Regra | Tamanho (base de 9.429) | Quantos evadem* |
|---|---|---|---|
| `CRITICA` | 20% do topo da ALTA (≈ 2% da base) | 189 | ~41% |
| `ALTA` | resto dos 10% do topo | 754 | ~25% |
| `MEDIA` | de 10% a 20% da base | 943 | ~16% |

\* média dos 5 meses, CV out-of-fold na coorte 2025/2; a média da base é 8,2%.

## Rodar

```powershell
pip install -r api/grad-presencial/requirements.txt
cd api/grad-presencial
uvicorn app:app --host 0.0.0.0 --port 8000
```

Conexão por variáveis de ambiente — as mesmas do job:

| Variável | |
|---|---|
| `EVASAO_SQL_SERVER` | servidor do banco |
| `EVASAO_SQL_USER` / `EVASAO_SQL_PASSWORD` | usuário SQL. Sem eles, usa o login Windows de quem roda |
| `EVASAO_SQL_DRIVER` | padrão `ODBC Driver 17 for SQL Server` |
| `EVASAO_API_TOKEN` | se definida, toda chamada exige `Authorization: Bearer <token>` |

**Defina o `EVASAO_API_TOKEN` em produção.** A tabela tem RA de aluno — dado pessoal.
Sem token, qualquer um na rede que alcance a porta consulta a fila. Com token, a
página `/docs` continua abrindo (só descreve as rotas); para testar por ela, clique
em **Authorize** e cole o token.

A conta do banco precisa **só de `SELECT`** em `BANCO_APP.dbo.TABELA_RISCO_PRESENCIAL`,
`BANCO_ERP.dbo.TABELA_CURSOS` (nome do curso) e `BANCO_ERP.dbo.TABELA_ALUNOS` (se o
RA existe e a situação atual, em `/alunos/{ra}`). Não use a conta do job,
que também apaga e insere.

## Testar

```powershell
cd api/grad-presencial
python teste_api.py
```

Chama cada rota contra o banco real (só leitura) e confere a resposta com uma contagem
feita direto no SQL — busca por nome, por código, com e sem acento, paginação, erros
(422, 404), aviso de fila atrasada, `/resumo`, `/datas`, os três casos de
`/alunos/{ra}` e token. Não depende de número fixo: vale
para qualquer dia gravado.
