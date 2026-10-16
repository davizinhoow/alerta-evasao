# Como usar a API de risco de evasão — Graduação EAD

Guia para quem vai **consumir** a API: o assistente com IA da equipe de retenção, um
painel, um script. Para instalar ou manter a API, veja o [README.md](README.md).

## O que a API responde

Toda **segunda e quinta**, por volta das 06:30, um job pontua os ~9.600 alunos da
graduação EAD do trimestre corrente e grava os de maior risco de evasão numa **fila**, em
três faixas. A fila de segunda vale até quarta; a de quinta, até domingo. A API só **lê**
essa fila — não calcula nada e não grava nada.

| Faixa | Quem entra | Tamanho típico | Ação da equipe |
|---|---|---|---|
| `CRITICA` | ~2% do topo da fila | ~190 | contato mais direto |
| `ALTA` | até 10% do topo | ~770 | notificação/mensagem |
| `MEDIA` | de 10% a 20% | ~960 | **nada** — só histórico |

**No EAD a operação notifica os 10% (a faixa ALTA).** A `MEDIA` é gravada só para medir
depois se valeria a pena ter falado com esse grupo. Aluno que **não aparece** na fila
está entre os ~80% de menor risco (ou não é da graduação EAD). A evasão trimestral da
modalidade gira em torno de 14 a 18%.

A faixa é uma **estimativa de risco**, não uma certeza: dos evasores reais, cerca de 3 a
4 em cada 10 caem no topo 10% da fila. A maioria dos alunos listados **não** evade — a
fila serve para a equipe **oferecer apoio**, não para rotular ninguém.

## Calendário: nem todo dia tem fila

O EAD é trimestral (períodos 71–74). Nos **primeiros 30 dias** de cada trimestre o job
não pontua — a coorte ainda está se formando. Depois disso há fila de segunda e quinta
até o fechamento do trimestre. Se `fila/datas` não traz nada recente, confira em
`fila/saude` se o trimestre corrente já passou dos 30 dias.

## Endereço e acesso

```
Base:   https://<servidor>/api_evasao_grad_ead/api
Local:  http://localhost/api_evasao_grad_ead/api
```

Toda chamada de dados precisa do token no cabeçalho:

```
Authorization: Bearer <token>
```

Sem o token, ou com o token errado, a resposta é `401`. **O token do EAD é diferente do
token do presencial.**

**Onde está o token:** no arquivo `api/lib/config.php` da própria pasta da API
(campo `EVASAO_API_TOKEN`). É fixo e fica no repositório — quem tem acesso ao repositório
já pode testar. Se um servidor trocar o token (pelo `config.local.php`), quem administra
aquele servidor informa o valor.

O token dá acesso a dado pessoal de aluno: **não** o coloque em página web, em código que
roda no navegador nem em mensagem para fora da equipe.

Duas rotas são abertas (não mostram aluno):

- `…/api/docs` — documentação interativa. Clique em **Authorize**, cole o token e teste.
- `…/api/openapi` — a especificação OpenAPI. É o que um assistente com IA lê para saber
  como chamar cada rota.

## As rotas

Todas são `GET`. Sem `data`, usam a **fila mais recente**.

### `alunos/listar` — quem está em risco

| Parâmetro | | Exemplo |
|---|---|---|
| `curso` | código ou trecho do nome; acento e maiúscula não importam | `pedagogia`, `administracao`, `7123` |
| `faixa` | uma ou mais: `CRITICA`, `ALTA`, `MEDIA` | `faixa=CRITICA&faixa=ALTA` ou `faixa=CRITICA,ALTA` |
| `data` | fila de um dia específico (`AAAA-MM-DD`) | `2026-07-30` |
| `pagina` / `limite` | paginação — `limite` até 1000, padrão 100 | `pagina=2&limite=50` |

```
GET alunos/listar?curso=pedagogia&faixa=CRITICA
```
```json
{
  "dt_corte": "2026-07-30",
  "fila_atualizada": true,
  "total": 24,
  "pagina": 1,
  "limite": 100,
  "alunos": [
    {"dt_corte": "2026-07-30", "posicao": 7, "aluno": "7201234", "chance_evasao": "CRITICA",
     "curso": "7123", "nome_curso": "PEDAGOGIA"}
  ]
}
```

- `total` = quantos atendem o filtro; `alunos` traz só a página pedida.
- `posicao` = posição na **fila geral do dia** (todos os cursos). O primeiro de pedagogia
  ser o 7º quer dizer que há 6 alunos de outros cursos com risco maior.

### `alunos/consultar?ra=` — um aluno específico

```
GET alunos/consultar?ra=7201234
```
```json
{
  "aluno": "7201234",
  "sit_aluno": "Ativo",
  "curso": "7123",
  "nome_curso": "PEDAGOGIA",
  "ultima_fila": "2026-07-30",
  "fila_atualizada": true,
  "faixa_ultima_fila": "CRITICA",
  "leitura": "CRITICA na fila de 2026-07-30, posicao 7.",
  "historico": [{"dt_corte": "2026-07-30", "posicao": 7, "chance_evasao": "CRITICA", "...": "..."}]
}
```

| Situação | Resposta |
|---|---|
| está numa faixa | `faixa_ultima_fila`: `"CRITICA"`, `"ALTA"` ou `"MEDIA"` |
| existe, mas está fora das faixas | `faixa_ultima_fila: null` |
| RA não existe | `404` |
| faltou o `?ra=` | `422` |

- O RA pode vir **sem o zero à esquerda** ou com ponto e traço — a API tenta as formas
  com 7 e 8 dígitos (o campo `aluno` da resposta traz o RA como o ERP academico grava).
- É `alunos/consultar?ra=7201234` — **não** `alunos/7201234`. Rota errada responde `404`
  com a lista de rotas válidas.
- `leitura` resume o resultado em uma frase — pronta para mostrar ou repetir.
- `sit_aluno` é a situação **de agora** no ERP academico. Um aluno pode estar na fila de ontem e
  já ter cancelado hoje — a `leitura` avisa.
- `dias` (opcional, padrão 30) = quantas filas anteriores olhar no `historico`.

### `fila/resumo` — quantos por curso

```
GET fila/resumo?curso=pedagogia
```
```json
[{"curso": "7123", "nome_curso": "PEDAGOGIA",
  "critica": 9, "alta": 28, "media": 41, "total": 78}]
```

Aceita `curso` e `data`. Ordenado do curso com mais alunos na CRITICA para o com menos.

### `fila/datas` — quais filas existem

```
GET fila/datas
```
```json
[{"dt_corte": "2026-07-30", "critica": 181, "alta": 721, "media": 902, "total": 1804}]
```

### `fila/saude` — a fila está em dia?

```
GET fila/saude
```
```json
{"status": "ok", "ultima_fila": "2026-07-30", "fila_esperada": "2026-07-30",
 "fila_atualizada": true, "dias_de_atraso": 0, "dias_gravados": 8}
```

## A regra mais importante: `fila_atualizada`

Se o job falhar, a API **não dá erro** — ela continua servindo a última fila gravada. Por
isso toda resposta traz `fila_atualizada`:

- `true` — é a fila mais recente que deveria existir: a da última segunda ou quinta (feita
  com os dados até a véspera).
- `false` — o job perdeu a última execução, ou você pediu uma `data` antiga, **ou** o
  trimestre corrente ainda está nos primeiros 30 dias (sem fila). Mostre a data
  (`dt_corte` / `ultima_fila`) e avise.

Na segunda e na quinta, antes das 8h, a fila anterior ainda conta como em dia (o job roda
às 06:30). `fila/saude` mostra a `fila_esperada` e os `dias_de_atraso`.

## Erros

Sempre em JSON: `{"error": "mensagem"}`.

| Código | Quando | O que fazer |
|---|---|---|
| `401` | sem token ou token errado | conferir o cabeçalho `Authorization` |
| `404` | rota inexistente; não há fila para a data pedida; RA não existe | a mensagem diz qual |
| `405` | método diferente de GET | só GET |
| `422` | parâmetro inválido | a mensagem diz qual e o formato certo |
| `503` | banco indisponível | tentar de novo em alguns minutos |

## Exemplos de chamada

**curl**
```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://<servidor>/api_evasao_grad_ead/api/alunos/listar?curso=pedagogia&faixa=CRITICA"
```

**PowerShell**
```powershell
$h = @{ Authorization = "Bearer $env:EVASAO_API_TOKEN" }
Invoke-RestMethod -Headers $h "http://localhost/api_evasao_grad_ead/api/alunos/consultar?ra=7201234"
```

**Python**
```python
import os, requests
base = "https://<servidor>/api_evasao_grad_ead/api"
h = {"Authorization": f"Bearer {os.environ['EVASAO_API_TOKEN']}"}
alunos, pagina = [], 1
while True:   # percorre todas as páginas
    r = requests.get(f"{base}/alunos/listar", headers=h,
                     params={"faixa": "ALTA", "pagina": pagina, "limite": 1000}).json()
    alunos += r["alunos"]
    if len(alunos) >= r["total"] or not r["alunos"]:
        break
    pagina += 1
```

## Para o assistente com IA

Configure a ferramenta com a especificação em `…/api/openapi` e o cabeçalho
`Authorization: Bearer <token>`. Instruções que o assistente **precisa** seguir:

1. **Confira `fila_atualizada`** em toda resposta. Se vier `false`, diga a data da fila e
   que ela não é a de hoje (pode ser trimestre nos primeiros 30 dias, sem fila).
2. **Nunca comunique a faixa ao próprio aluno** nem diga a um aluno que ele "vai evadir".
   A maioria dos listados não evade; a fila serve para a equipe **oferecer apoio**. É dado
   pessoal (LGPD).
3. **A operação do EAD age sobre os 10% (ALTA + CRITICA).** A `MEDIA` é só histórico — não
   entra em disparo.
4. **"Não está na fila" não é "sem risco nenhum"** — é "não está entre os 20% de maior
   risco" (ou não é da graduação EAD). Use a `leitura` de `alunos/consultar`.
5. **`posicao` é da modalidade inteira**, não do curso filtrado.
6. **Situação atual manda:** se `sit_aluno` não for `Ativo`, o aluno já saiu — não há o
   que reter.

Perguntas que a equipe costuma fazer e a rota que responde:

| Pergunta | Chamada |
|---|---|
| "Quais alunos de pedagogia estão críticos?" | `alunos/listar?curso=pedagogia&faixa=CRITICA` |
| "Quantos alunos em risco tem em Administração?" | `fila/resumo?curso=administracao` |
| "O aluno 7201234 está em risco?" | `alunos/consultar?ra=7201234` |
| "Ele piorou nas últimas semanas?" | `alunos/consultar?ra=...&dias=30` → `historico` |
| "Qual curso tem mais alunos críticos?" | `fila/resumo` (já vem ordenado) |
| "A lista de hoje já saiu?" | `fila/saude` |
