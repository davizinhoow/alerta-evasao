# Como usar a API de risco de evasão — Graduação (Presencial + EAD + Híbrido)

Guia para quem vai **consumir** a API: o assistente com IA da equipe de retenção, um
painel, um script. Para instalar ou manter, veja o [README.md](README.md).

## A regra número 1: a modalidade vem no começo da URL

Esta API atende as **modalidades da graduação** pela mesma porta. Escolha pelo 1º segmento:

```
presencial/...   → graduação presencial     (tabela TABELA_RISCO_PRESENCIAL)
ead/...          → graduação EAD             (tabela TABELA_RISCO_EAD)
hibrido/...      → graduação híbrida         (tabela TABELA_RISCO_HIBRIDO)
```

`presencial/alunos/listar`, `ead/fila/saude`, e assim por diante. Esquecer a modalidade
dá **404** com a dica do que faltou.

## O que a API responde

Toda **segunda e quinta**, um job pontua os alunos de cada modalidade e grava os de maior
risco numa **fila**, em três faixas. A API só **lê** essa fila — não calcula nem grava
nada. Se o job parar, ela serve a fila velha e avisa por `fila_atualizada: false`.

| Faixa | Quem entra | Quantos evadem de fato* |
|---|---|---|
| `CRITICA` | ~2% do topo da fila | ~4 em cada 10 |
| `ALTA` | até 10% do topo | ~1 em cada 4 |
| `MEDIA` | de 10% a 20% | ~1 em cada 6 |

Aluno que **não aparece** está entre os ~80% de menor risco (ou não é daquela
modalidade). A faixa é **estimativa de risco**, não certeza: a maioria da CRITICA **não**
evade.

\* ordens de grandeza medidas no presencial (coorte 2025/2). O EAD tem perfil parecido;
veja os números por coorte no `CLAUDE.md` do projeto.

## Endereço e acesso

```
Base:  https://<servidor>/<pasta>/api
```

Toda chamada de dados leva o token no cabeçalho:

```
Authorization: Bearer <token>
```

**Um token só, FIXO e igual para todos** — o mesmo valor para as duas modalidades e para
todos os consumidores (não há token por usuário nem por modalidade; a modalidade vem na
URL). Está em `api/lib/config.php` (`EVASAO_API_TOKEN`); é fixo e versionado — quem tem o
repositório já testa, sem configurar nada.

O token dá acesso a dado pessoal de aluno: **não** o coloque em página web, em código que
roda no navegador, nem em mensagem para fora da equipe.

Duas rotas são abertas e **sem** prefixo de modalidade (não mostram aluno):

- `…/api/docs` — documentação interativa. Em **Authorize**, cole o token; há um dropdown
  para escolher `presencial`/`ead`/`hibrido` em cada rota.
- `…/api/openapi` — a especificação OpenAPI, que o assistente lê.

## As rotas

Todas são `GET` e começam pela modalidade. Sem `data`, usam a **fila mais recente**.

### `{mod}/alunos/listar` — quem está em risco

| Parâmetro | | Exemplo |
|---|---|---|
| `curso` | código ou trecho do nome; acento e maiúscula não importam | `engenharia`, `258` |
| `faixa` | uma ou mais: `CRITICA`, `ALTA`, `MEDIA` | `faixa=CRITICA&faixa=ALTA` ou `faixa=CRITICA,ALTA` |
| `data` | fila de um dia específico (`AAAA-MM-DD`) | `2026-10-04` |
| `pagina` / `limite` | paginação — `limite` até 1000, padrão 100 | `pagina=2&limite=50` |

```
GET presencial/alunos/listar?curso=direito&faixa=CRITICA
```
```json
{
  "dt_corte": "2026-10-04",
  "fila_atualizada": true,
  "total": 159,
  "pagina": 1,
  "limite": 100,
  "alunos": [
    {"dt_corte": "2026-10-04", "posicao": 1, "aluno": "1700747", "chance_evasao": "CRITICA",
     "curso": "12", "nome_curso": "BACHARELADO EM DIREITO"}
  ]
}
```

- `total` = quantos atendem o filtro; `alunos` traz só a página pedida.
- `posicao` = posição na **fila geral da modalidade** (todos os cursos).

### `{mod}/alunos/consultar?ra=` — um aluno específico

```
GET ead/alunos/consultar?ra=9000002
```
```json
{
  "aluno": "9000002", "sit_aluno": "Concluido", "curso": "7008",
  "nome_curso": "BACHARELADO EM ADMINISTRAÇÃO",
  "ultima_fila": "2026-07-30", "fila_atualizada": false,
  "faixa_ultima_fila": "CRITICA",
  "leitura": "CRITICA na fila de 2026-07-30, posicao 1. Situacao atual no ERP academico: Concluido. ATENCAO: fila desatualizada ...",
  "fatores": ["sem acessar o AVA ha 165 dias"],
  "historico": [{"dt_corte": "2026-07-30", "posicao": 1, "chance_evasao": "CRITICA", "...": "..."}]
}
```

| Situação | Resposta |
|---|---|
| está numa faixa | `faixa_ultima_fila`: `"CRITICA"`, `"ALTA"` ou `"MEDIA"` |
| existe, mas fora das faixas | `faixa_ultima_fila: null` |
| RA não existe | `404` |
| faltou o `?ra=` | `422` |

- O RA pode vir **sem o zero à esquerda** ou com ponto e traço: `527810` e `052.781-0`
  acham `0527810`.
- É `…/alunos/consultar?ra=9000002` — **não** `…/alunos/9000002`.
- `leitura` resume em uma frase e cita a modalidade. `sit_aluno` é a situação **de agora**
  no ERP academico (pode ter mudado desde a fila). `dias` (padrão 30) = quantas filas olhar no
  `historico`.
- `fatores` = **onde o aluno tem mais dificuldade** (lista de frases, do mais forte ao mais
  fraco). No **presencial** e no **EAD**, para quem está na fila, vêm dos motivos gravados
  pelo **job** (AVA + acadêmico) mais o **financeiro ao vivo**; no **híbrido** e para quem
  está fora da fila, tudo **ao vivo** (AVA + financeiro). Pode vir `[]` (sem fator isolado).
  **Descritivo** (não é a razão exata do modelo) e **uso interno** — nunca para o aluno (LGPD).

### `{mod}/fila/resumo` — quantos por curso
```
GET presencial/fila/resumo?curso=engenharia
```
```json
[{"curso": "258", "nome_curso": "BACHARELADO EM ENGENHARIA MECÂNICA",
  "critica": 19, "alta": 33, "media": 53, "total": 105}]
```
Aceita `curso` e `data`. Ordenado do curso com mais CRITICA para o com menos.

### `{mod}/fila/datas` — quais filas existem
```
GET ead/fila/datas
```
```json
[{"dt_corte": "2026-07-30", "critica": 181, "alta": 721, "media": 902, "total": 1804}]
```

### `{mod}/fila/saude` — a fila está em dia?
```
GET presencial/fila/saude
```
```json
{"status": "ok", "ultima_fila": "2026-10-04", "fila_esperada": "2026-10-04",
 "fila_atualizada": true, "dias_de_atraso": 0, "dias_gravados": 4}
```

## A regra mais importante: `fila_atualizada`

Se o job falhar, a API **não dá erro** — serve a última fila gravada. Por isso toda
resposta de fila traz `fila_atualizada`:

- `true` — é a fila mais recente esperada (da última segunda ou quinta).
- `false` — o job perdeu a última execução, ou você pediu uma `data` antiga. Mostre a
  data e avise que está desatualizada.

Na segunda e na quinta, antes das 8h, a fila anterior ainda conta como em dia (o job roda
~06:30). `{mod}/fila/saude` mostra `fila_esperada` e `dias_de_atraso`. **Cada modalidade
tem o seu calendário de fila** — confira a saúde da modalidade que vai usar.

## Erros — sempre `{"error": "mensagem"}`

| Código | Quando | O que fazer |
|---|---|---|
| `401` | sem token ou token errado | conferir o `Authorization` |
| `404` | **modalidade ausente/inválida**; rota inexistente; sem fila para a data; RA não existe | a mensagem diz qual |
| `405` | método diferente de GET | só GET |
| `422` | parâmetro inválido | a mensagem diz o formato certo |
| `503` | banco indisponível, ou API sem token | tentar de novo / avisar o admin |

## Exemplos de chamada

**curl**
```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://<servidor>/<pasta>/api/presencial/alunos/listar?curso=engenharia&faixa=CRITICA"
```

**PowerShell**
```powershell
$h = @{ Authorization = "Bearer $env:EVASAO_API_TOKEN" }
Invoke-RestMethod -Headers $h "http://localhost/<pasta>/api/ead/alunos/consultar?ra=9000002"
```

**Python** (percorre todas as páginas de uma modalidade)
```python
import os, requests
base = "https://<servidor>/<pasta>/api"
mod  = "ead"   # ou "presencial"
h = {"Authorization": f"Bearer {os.environ['EVASAO_API_TOKEN']}"}
alunos, pagina = [], 1
while True:
    r = requests.get(f"{base}/{mod}/alunos/listar", headers=h,
                     params={"faixa": "CRITICA", "pagina": pagina, "limite": 1000}).json()
    alunos += r["alunos"]
    if len(alunos) >= r["total"] or not r["alunos"]:
        break
    pagina += 1
```

## Para o assistente com IA

Configure a ferramenta com a especificação em `…/api/openapi` e o cabeçalho
`Authorization: Bearer <token>`. Regras que o assistente **precisa** seguir:

1. **Escolha a modalidade certa** no começo da rota (`presencial`, `ead` ou `hibrido`). Se o
   usuário não disser, pergunte — as filas são separadas.
2. **Confira `fila_atualizada`** em toda resposta. `false` → diga a data e que não é a de
   hoje.
3. **Nunca comunique a faixa ao próprio aluno** nem diga que ele "vai evadir". A maioria
   da CRITICA não evade; a fila serve para a equipe **oferecer apoio** (LGPD).
4. **"Não está na fila" não é "sem risco"** — é "não está entre os 20% de maior risco"
   (ou não é daquela modalidade). Use a `leitura` de `alunos/consultar`.
5. **`posicao` é da modalidade inteira**, não do curso filtrado.
6. **Situação atual manda:** se `sit_aluno` não for `Ativo`, o aluno já saiu.

| Pergunta | Chamada |
|---|---|
| "Quais alunos de engenharia estão críticos no presencial?" | `presencial/alunos/listar?curso=engenharia&faixa=CRITICA` |
| "Quantos alunos em risco tem em Administração no EAD?" | `ead/fila/resumo?curso=administracao` |
| "O aluno 9000002 do EAD está em risco?" | `ead/alunos/consultar?ra=9000002` |
| "Ele piorou nas últimas semanas?" | `{mod}/alunos/consultar?ra=...&dias=30` → `historico` |
| "A lista de hoje do presencial já saiu?" | `presencial/fila/saude` |
