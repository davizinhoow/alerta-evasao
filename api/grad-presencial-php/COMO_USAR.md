# Como usar a API de risco de evasão — Graduação Presencial

Guia para quem vai **consumir** a API: o assistente com IA da equipe de retenção, um
painel, um script. Para instalar ou manter a API, veja o [README.md](README.md).

## O que a API responde

Toda **segunda e quinta**, por volta das 06:30, um job pontua os ~9.400 alunos da
graduação presencial e grava os de maior risco de evasão numa **fila**, em três faixas.
A fila de segunda vale até quarta; a de quinta, até domingo. A API só **lê** essa
fila — não calcula nada e não grava nada.

| Faixa | Quem entra | Tamanho típico | Quantos evadem de fato* |
|---|---|---|---|
| `CRITICA` | ~2% do topo da fila | ~190 | ~4 em cada 10 |
| `ALTA` | até 10% do topo | ~750 | ~1 em cada 4 |
| `MEDIA` | de 10% a 20% | ~940 | ~1 em cada 6 |

Aluno que **não aparece** na fila está entre os ~80% de menor risco (ou não é da
graduação presencial). A média de evasão da escola é ~8%.

\* medido na coorte 2025/2. A faixa é uma **estimativa de risco**, não uma certeza:
a maioria dos alunos da CRITICA **não** evade.

## Endereço e acesso

```
Base:   https://<servidor>/api_evasao_grad_presencial/api
Local:  http://localhost/api_evasao_grad_presencial/api
```

Toda chamada de dados precisa do token no cabeçalho:

```
Authorization: Bearer <token>
```

Sem o token, ou com o token errado, a resposta é `401`.

**Onde está o token:** no arquivo `api/lib/config.php` da própria pasta da API
(campo `EVASAO_API_TOKEN`). É fixo e fica no repositório app-web — quem tem acesso ao
repositório já pode testar. Se um servidor trocar o token (pelo `config.local.php`),
quem administra aquele servidor informa o valor.

O token dá acesso a dado pessoal de aluno: **não** o coloque em página web, em código
que roda no navegador nem em mensagem para fora da equipe.

Duas rotas são abertas (não mostram aluno):

- `…/api/docs` — documentação interativa. Clique em **Authorize**, cole o token e teste
  cada rota pelo navegador.
- `…/api/openapi` — a especificação OpenAPI. É o que um assistente com IA lê para saber
  como chamar cada rota.

## As rotas

Todas são `GET`. Sem `data`, usam a **fila mais recente**.

### `alunos/listar` — quem está em risco

| Parâmetro | | Exemplo |
|---|---|---|
| `curso` | código ou trecho do nome; acento e maiúscula não importam | `engenharia`, `mecanica`, `258` |
| `faixa` | uma ou mais: `CRITICA`, `ALTA`, `MEDIA` | `faixa=CRITICA&faixa=ALTA` ou `faixa=CRITICA,ALTA` |
| `data` | fila de um dia específico (`AAAA-MM-DD`) | `2026-09-23` |
| `pagina` / `limite` | paginação — `limite` até 1000, padrão 100 | `pagina=2&limite=50` |

```
GET alunos/listar?curso=engenharia&faixa=CRITICA
```
```json
{
  "dt_corte": "2026-09-23",
  "fila_atualizada": true,
  "total": 36,
  "pagina": 1,
  "limite": 100,
  "alunos": [
    {"dt_corte": "2026-09-23", "posicao": 11, "aluno": "9000003", "chance_evasao": "CRITICA",
     "curso": "215", "nome_curso": "BACHARELADO EM ENGENHARIA QUÍMICA"}
  ]
}
```

- `total` = quantos atendem o filtro; `alunos` traz só a página pedida.
- `posicao` = posição na **fila geral da escola** (todos os cursos). O primeiro de
  engenharia ser o 11º quer dizer que há 10 alunos de outros cursos com risco maior.

### `alunos/consultar?ra=` — um aluno específico

```
GET alunos/consultar?ra=9000001
```
```json
{
  "aluno": "9000001",
  "sit_aluno": "Ativo",
  "curso": "146",
  "nome_curso": "SUPERIOR DE TECNOLOGIA EM LOGÍSTICA",
  "ultima_fila": "2026-09-23",
  "fila_atualizada": true,
  "faixa_ultima_fila": "CRITICA",
  "leitura": "CRITICA na fila de 2026-09-23, posicao 1.",
  "historico": [{"dt_corte": "2026-09-23", "posicao": 1, "chance_evasao": "CRITICA", "...": "..."}]
}
```

| Situação | Resposta |
|---|---|
| está numa faixa | `faixa_ultima_fila`: `"CRITICA"`, `"ALTA"` ou `"MEDIA"` |
| existe, mas está fora das faixas | `faixa_ultima_fila: null` |
| RA não existe | `404` |
| faltou o `?ra=` | `422` |

- O RA pode vir **sem o zero à esquerda** ou com ponto e traço: `527810` e `052.781-0`
  acham o aluno `0527810` (o campo `aluno` da resposta traz o RA como o ERP academico grava).
- É `alunos/consultar?ra=9000001` — **não** `alunos/9000001`. Rota errada responde `404`
  com a lista de rotas válidas.
- `leitura` resume o resultado em uma frase — pronta para mostrar ou repetir.
- `sit_aluno` é a situação **de agora** no ERP academico. Um aluno pode estar na fila de ontem
  e já ter cancelado hoje — a `leitura` avisa.
- `dias` (opcional, padrão 30) = quantas filas anteriores olhar no `historico`.

### `fila/resumo` — quantos por curso

```
GET fila/resumo?curso=engenharia
```
```json
[{"curso": "258", "nome_curso": "BACHARELADO EM ENGENHARIA MECÂNICA",
  "critica": 19, "alta": 33, "media": 53, "total": 105}]
```

Aceita `curso` e `data`. Ordenado do curso com mais alunos na CRITICA para o com menos.

### `fila/datas` — quais filas existem

```
GET fila/datas
```
```json
[{"dt_corte": "2026-09-23", "critica": 189, "alta": 754, "media": 943, "total": 1886}]
```

### `fila/saude` — a fila está em dia?

```
GET fila/saude
```
```json
{"status": "ok", "ultima_fila": "2026-09-24", "fila_esperada": "2026-09-24",
 "fila_atualizada": true, "dias_de_atraso": 0, "dias_gravados": 12}
```

## A regra mais importante: `fila_atualizada`

Se o job falhar, a API **não dá erro** — ela continua servindo a última fila gravada.
Por isso toda resposta traz `fila_atualizada`:

- `true` — é a fila mais recente que deveria existir: a da última segunda ou quinta
  (feita com os dados até a véspera). Numa quarta, por exemplo, a fila de segunda está
  **em dia** — não é atraso.
- `false` — o job perdeu a última execução, ou você pediu uma `data` antiga. Mostre a
  data (`dt_corte` / `ultima_fila`) e avise que a fila está desatualizada.

Na segunda e na quinta, antes das 8h, a fila anterior ainda conta como em dia (o job
roda às 06:30). `fila/saude` mostra a `fila_esperada` e os `dias_de_atraso`.

## Erros

Sempre em JSON: `{"error": "mensagem"}`.

| Código | Quando | O que fazer |
|---|---|---|
| `401` | sem token ou token errado | conferir o cabeçalho `Authorization` |
| `404` | rota inexistente; não há fila para a data pedida; RA não existe | a mensagem diz qual — rota errada lista as válidas; datas válidas em `fila/datas` |
| `405` | método diferente de GET | só GET |
| `422` | parâmetro inválido | a mensagem diz qual e o formato certo |
| `503` | banco indisponível | tentar de novo em alguns minutos |

## Exemplos de chamada

**curl**
```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://<servidor>/api_evasao_grad_presencial/api/alunos/listar?curso=engenharia&faixa=CRITICA"
```

**PowerShell**
```powershell
$h = @{ Authorization = "Bearer $env:EVASAO_API_TOKEN" }
Invoke-RestMethod -Headers $h "http://localhost/api_evasao_grad_presencial/api/alunos/consultar?ra=9000001"
```

**PHP**
```php
$ctx = stream_context_create(['http' => [
    'header' => 'Authorization: Bearer ' . getenv('EVASAO_API_TOKEN'),
    'ignore_errors' => true,
]]);
$base = 'https://<servidor>/api_evasao_grad_presencial/api';
$fila = json_decode(file_get_contents("$base/alunos/listar?faixa=CRITICA", false, $ctx), true);
if (!$fila['fila_atualizada']) {
    echo "Atenção: fila de {$fila['dt_corte']}, não é a de hoje\n";
}
```

**JavaScript (servidor, Node 18+)**
```js
const base = "https://<servidor>/api_evasao_grad_presencial/api";
const r = await fetch(`${base}/fila/resumo?curso=engenharia`, {
  headers: { Authorization: `Bearer ${process.env.EVASAO_API_TOKEN}` },
});
if (!r.ok) throw new Error((await r.json()).error);
const resumo = await r.json();
```

**Python**
```python
import os, requests
base = "https://<servidor>/api_evasao_grad_presencial/api"
h = {"Authorization": f"Bearer {os.environ['EVASAO_API_TOKEN']}"}
alunos, pagina = [], 1
while True:   # percorre todas as páginas
    r = requests.get(f"{base}/alunos/listar", headers=h,
                     params={"faixa": "CRITICA", "pagina": pagina, "limite": 1000}).json()
    alunos += r["alunos"]
    if len(alunos) >= r["total"] or not r["alunos"]:
        break
    pagina += 1
```

## Para o assistente com IA

Configure a ferramenta com a especificação em `…/api/openapi` e o cabeçalho
`Authorization: Bearer <token>`. Instruções que o assistente **precisa** seguir:

1. **Confira `fila_atualizada`** em toda resposta. Se vier `false`, diga a data da fila
   e que ela não é a de hoje.
2. **Nunca comunique a faixa ao próprio aluno** nem diga a um aluno que ele "vai evadir".
   A maioria dos alunos da CRITICA não evade; a fila serve para a equipe **oferecer
   apoio**, não para rotular ninguém. É dado pessoal (LGPD).
3. **"Não está na fila" não é "sem risco nenhum"** — é "não está entre os 20% de maior
   risco" (ou não é da graduação presencial). Use a `leitura` de `alunos/consultar`.
4. **`posicao` é da escola inteira**, não do curso filtrado.
5. **Situação atual manda:** se `sit_aluno` não for `Ativo`, o aluno já saiu — não há o
   que reter.

Perguntas que a equipe costuma fazer e a rota que responde:

| Pergunta | Chamada |
|---|---|
| "Quais alunos de engenharia estão críticos?" | `alunos/listar?curso=engenharia&faixa=CRITICA` |
| "Quantos alunos em risco tem em Direito?" | `fila/resumo?curso=direito` |
| "O aluno 9000001 está em risco?" | `alunos/consultar?ra=9000001` |
| "Ele piorou nas últimas semanas?" | `alunos/consultar?ra=...&dias=30` → `historico` |
| "Qual curso tem mais alunos críticos?" | `fila/resumo` (já vem ordenado) |
| "A lista de hoje já saiu?" | `fila/saude` |
