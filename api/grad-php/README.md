# API de consulta — risco de evasão, Graduação (Presencial + EAD + Híbrido) — PHP

**Uma API, as modalidades da graduação.** Junta num só lugar o que antes eram
[`../grad-presencial-php/`](../grad-presencial-php/) e
[`../grad-ead-php/`](../grad-ead-php/) — código idêntico, só mudavam a tabela e o
token. Aqui a modalidade é o **1º segmento da URL**:

```
/presencial/...  -> le TABELA_RISCO_PRESENCIAL
/ead/...         -> le TABELA_RISCO_EAD
/hibrido/...     -> le TABELA_RISCO_HIBRIDO
```

Mesmo formato das APIs do app-web (`agendamento-new/api`): um arquivo por endpoint,
`cors.php` com `setCors()`/`json()`/`error()`, `lib/` com as regras, URL sem extensão,
conexão pelo `conexao.php` do app-web.

```
integrador (seg e qui) → job/pontuar_{presencial,ead}.py → TABELA_RISCO_* → esta API
```

**A API só lê.** Não roda modelo nem grava nada. Se o job parar, ela serve a fila
velha e avisa por `fila_atualizada: false`.

**Vai consumir a API?** Veja o [COMO_USAR.md](COMO_USAR.md).

## O que muda em relação às duas APIs separadas

| | Separadas (antigas) | Unificada (esta) |
|---|---|---|
| Rotas | `/alunos/listar` | `/{presencial\|ead\|hibrido}/alunos/listar` |
| Tabela | fixa (uma por pasta) | escolhida pela URL |
| Token | um por modalidade | **um só** (`evasao-instituicao-...`) |
| Código do endpoint | duplicado nas duas pastas | um só |

O **híbrido** já está roteado (`MODALIDADES`/`ROTULOS` em `api/lib/fila.php` +
`api/.htaccess`): `/hibrido/...` lê `TABELA_RISCO_HIBRIDO`. A tabela é alimentada
por fora deste projeto; o modelo híbrido **não** foi publicado oficialmente aqui
(decisão de 02/10/2026 — empata com a regra de uma coluna), então confira a origem
da fila antes de expô-la. O híbrido ainda **não** grava `MOTIVO*`: os `fatores`
dele saem ao vivo (ver `MODALIDADES_COM_MOTIVO` em `api/lib/fatores.php`).

## Estrutura

```
grad-php/
├── .gitignore                   ignora api/lib/config.local.php
├── .htaccess                    bloqueia o teste e os .md
├── README.md  COMO_USAR.md
├── router_teste.php             SO para o teste: emula o api/.htaccess no php -S
├── teste_api.php                teste contra o banco real (as duas modalidades)
└── api/
    ├── .htaccess                modalidade no 1o segmento + URL sem extensao + 404 JSON + token
    ├── cors.php                 setCors(), apenasGet(), json(), error()
    ├── auth.php                 requireToken() (token unico)
    ├── alunos/
    │   ├── listar.php           alunos em risco, com filtro
    │   └── consultar.php        situacao de um aluno (?ra=)
    ├── fila/
    │   ├── resumo.php           contagem por curso e faixa
    │   ├── datas.php            filas gravadas
    │   └── saude.php            o banco responde? a fila e a de hoje?
    ├── openapi.php  docs.php     especificacao + Swagger — abertos, agnosticos de modalidade
    ├── nao_encontrado.php        404 em JSON (o .htaccess manda para ca)
    └── lib/                      bloqueada pelo .htaccess
        ├── banco.php            conexao: conexao.php do app-web ou variaveis
        ├── config.php           token unico — VERSIONADO
        ├── config.exemplo.php   modelo do config.local.php (opcional)
        ├── fila.php             modalidade(), tabela(), validacao, datas, filtro, consulta
        ├── fatores.php          motivos do aluno: MOTIVO* da tabela (presencial, EAD) + ao vivo (AVA/financeiro)
        └── openapi.php          a especificacao — mude junto se mexer num endpoint
```

## Como a modalidade é escolhida

O `api/.htaccess` tem uma regra a mais que as APIs separadas:

```apache
RewriteRule ^(presencial|ead|hibrido)/(alunos/(?:listar|consultar)|fila/(?:resumo|datas|saude))/?$ $2.php?modalidade=$1 [QSA,END]
```

Ela injeta `?modalidade=` **antes** da query do usuário (o `[QSA]` acrescenta o resto
depois). O `modalidade()` em `api/lib/fila.php` lê a **primeira** ocorrência — então
quem repetir `?modalidade=` na mão **não** troca a tabela definida pela rota. Sem
modalidade válida no começo da URL: **404**.

`docs` e `openapi` ficam fora dessa regra: são agnósticos de modalidade (o openapi
descreve as rotas como `/{modalidade}/...`, com um dropdown no Swagger).

> **O teste** (`teste_api.php`) sobe o `php -S`, que **não lê `.htaccess`**. Por isso
> existe o `router_teste.php`: ele emula essa regra para o teste poder chamar a URL com
> prefixo de modalidade. Em produção quem roteia é o Apache.

## Endpoints (GET)

Base: `https://servidor/<pasta>/api` — e toda rota de dados começa pela modalidade.

| Endpoint | O que devolve |
|---|---|
| `{mod}/alunos/listar` | alunos em risco — filtros `curso`, `faixa`, `data`, `pagina`, `limite` |
| `{mod}/alunos/consultar?ra=` | situação do aluno na fila mais recente + histórico (`dias`) |
| `{mod}/fila/resumo` | contagem por curso e faixa — filtros `curso`, `data` |
| `{mod}/fila/datas` | filas gravadas, com o total de cada faixa (`limite`) |
| `{mod}/fila/saude` | o banco responde e se a fila está atrasada |
| `docs` | documentação interativa — **Authorize** para colar o token |
| `openapi` | especificação OpenAPI (é o que o assistente lê) |

`{mod}` = `presencial`, `ead` ou `hibrido`. Sem `data`, tudo usa a **fila mais recente**
daquela modalidade. Toda resposta de fila traz `fila_atualizada`. **Quem consome tem que
conferir esse campo.**

- `curso`: código (`258`) ou trecho do nome (`engenharia`, `mecanica`) — acento e
  maiúscula não importam.
- `faixa`: `CRITICA`, `ALTA`, `MEDIA`. Várias: `faixa=CRITICA&faixa=ALTA`, `faixa[]=...`
  ou `faixa=CRITICA,ALTA`.
- `posicao`: posição na **fila geral do dia** da modalidade (todos os cursos).
- Aluno fora da tabela = **sem risco** naquele dia.

```
presencial/alunos/listar?curso=engenharia&faixa=CRITICA
ead/alunos/listar?faixa=CRITICA&data=2026-07-30
presencial/fila/resumo?curso=direito
ead/alunos/consultar?ra=9000002
```

### `alunos/consultar` — três respostas, sem ambiguidade

| Caso | Resposta |
|---|---|
| RA não existe no ERP academico | **404** |
| existe, fora das faixas na fila mais recente | 200, `faixa_ultima_fila: null` |
| está numa faixa | 200, `faixa_ultima_fila: "CRITICA"` (ou `ALTA`, `MEDIA`) |
| sem `?ra=` | **422** |

O campo `leitura` resume em uma frase e cita a modalidade consultada. `sit_aluno` é a
situação **atual** no ERP academico — o aluno pode estar na fila de ontem e já ter saído hoje.

O campo **`fatores`** (lista de frases) diz **onde o aluno tem mais dificuldade**, do mais
forte ao mais fraco. No **presencial** e no **EAD**, para quem está na fila, vem dos campos
`MOTIVO*` que o **job** grava (AVA + acadêmico) mais o **financeiro ao vivo**; no **híbrido**
(que ainda não grava `MOTIVO*`) e para quem está fora da fila, tudo **ao vivo** (AVA +
financeiro). Pode vir `[]` (sem fator isolado). **Descritivo** (não é a razão exata do
modelo) e **uso interno** — nunca para o aluno (LGPD). Quais modalidades leem `MOTIVO*` da
tabela está em `MODALIDADES_COM_MOTIVO` (`api/lib/fatores.php`); quando o híbrido passar a
gravar motivos, basta incluir `'hibrido'` ali.

### Erros — formato do app-web: `{"error": "mensagem"}`

| Código | Quando |
|---|---|
| 401 | sem token ou token errado (traz `WWW-Authenticate: Bearer`) |
| 404 | modalidade ausente/inválida; rota inexistente; sem fila para a `data`; RA inexistente |
| 405 | método diferente de GET |
| 422 | parâmetro inválido (faixa, data, limite, `ra` ausente) |
| 503 | banco indisponível, ou API sem token configurado |

## Token único — fixo e igual para todos

**Um token só, FIXO para todos** — o mesmo valor para as **duas modalidades** e para
**todos os consumidores** (assistente, painel, script). Não há token por usuário nem
por modalidade: a modalidade vem na URL, não no token; o `auth.php` compara o header
`Authorization: Bearer` com esse único `EVASAO_API_TOKEN`.

Fica **fixo e versionado** em `api/lib/config.php` (vai para o git de propósito,
decisão de 25/09/2026, reafirmada em 05/10/2026) — quem tem a pasta já chama a API sem
configurar nada. Em troca, quem tem acesso ao repositório conhece o token.

- Quem tem o token tem acesso a tudo; não dá para revogar o de um sem trocar o de todos.
- **Sem token nenhum a API recusa tudo (503).** Teste rápido pós-deploy: `presencial/fila/saude`
  sem token deve dar `401`.
- (Opcional, por servidor) dá para sobrescrever com `api/lib/config.local.php` (fora do
  git) ou `SetEnv EVASAO_API_TOKEN`; ordem: ambiente > `config.local.php` > `config.php`.
  Mas o **padrão é o fixo versionado** — não precisa criar nada.

## Variáveis de ambiente

Com `EVASAO_SQL_SERVER` definida, a API usa as variáveis; sem ela, procura o
`conexao.php` do app-web subindo pelas pastas (`Conexao::getConnection('instituicao')`).

| Variável | |
|---|---|
| `EVASAO_SQL_SERVER` | servidor do banco. Sem ela, usa o `conexao.php` do app-web |
| `EVASAO_SQL_USER` / `EVASAO_SQL_PASSWORD` | login SQL; sem eles, login Windows do processo |
| `EVASAO_API_TOKEN` | token de toda chamada (menos `docs`/`openapi`). Padrão: o fixo de `config.php` |
| `EVASAO_API_ABERTA` | `1` abre sem token — só teste local |
| `EVASAO_PDO_DRIVER` | `auto` (padrão), `sqlsrv` ou `odbc` |
| `EVASAO_SQL_DRIVER` | só `pdo_odbc`: padrão `ODBC Driver 17 for SQL Server` |
| `EVASAO_SQL_TRUST_CERT` | `1` (padrão) aceita o certificado do servidor |
| `EVASAO_ODBC_CHARSET` | só `pdo_odbc`: padrão `Windows-1252` no Windows, `UTF-8` fora |
| `EVASAO_DIAS_JOB` | dias em que o job roda, ISO (1=seg…7=dom). Padrão `1,4` |
| `EVASAO_HORA_JOB` | hora em que o job já terminou (padrão `8`) |
| `EVASAO_TZ` | fuso do atraso da fila (padrão `America/Sao_Paulo`) |

## Requisitos

- **PHP 7.3+** (o app-web roda 7.3; o `teste_api.php` precisa de 7.4+).
- `pdo_sqlsrv` (app-web) ou `pdo_odbc`.
- Apache com `mod_rewrite` e `AllowOverride All`. Sem reescrita as URLs funcionam com
  `.php` **e** `?modalidade=` na mão: `api/alunos/listar.php?modalidade=ead`.

## Testar

```powershell
cd api/grad-php
$env:EVASAO_SQL_SERVER = "servidor"   # ou rode dentro do app-web, sem variavel
php teste_api.php
```

Sobe a API no `php -S` (com o `router_teste.php`) e roda a suíte completa **nas duas
modalidades**, conferindo cada resposta com contagem feita direto no SQL: busca por nome
(com e sem acento), por código, faixas nos três formatos, paginação, caracteres
especiais, erros (401/404/405/422), CORS, acentos no JSON, `alunos/consultar`, mais os
testes de roteamento por modalidade (modalidade ausente/inválida → 404; cada modalidade
na sua tabela; anti-spoof do `?modalidade=` na mão) e o token único. Uma modalidade sem
fila gravada (em `PREPARADAS`, como o híbrido quando sua tabela estiver vazia) confere só
o roteamento, sem falhar a suíte.

**Validado em 06/10/2026** contra o banco real (`BANCO_APP`): presencial
(fila de 2026-10-04), EAD (fila de 2026-07-30) e híbrido (fila de 2026-10-04): **TUDO OK**.

## Deploy

**Fonte da verdade:** esta pasta (`machine-learning-evasao/api/grad-php`). Uma **cópia**
roda no app-web como `api_evasao_grad` (ao lado do `conexao.php`, servida pelo Apache). A
receita é a das APIs separadas: copiar para a raiz do app-web, nada a configurar além de,
opcionalmente, trocar o token.

> **Mexeu aqui, replique lá.** Quando um arquivo desta pasta mudar, re-copie-o para a cópia
> do app-web. A última mudança (06/10/2026) toca **9 arquivos**: `api/lib/fatores.php`
> (liga o EAD em `MODALIDADES_COM_MOTIVO`), `api/lib/fila.php`, `api/.htaccess`,
> `router_teste.php`, `api/nao_encontrado.php`, `api/lib/openapi.php`, `teste_api.php`
> (preparação do híbrido) e os dois `.md`. Nenhum arquivo de conexão/token muda.

As tabelas `TABELA_RISCO_PRESENCIAL`, `TABELA_RISCO_EAD` e `TABELA_RISCO_HIBRIDO` já
existem; as duas primeiras são alimentadas pelos jobs do integrador
(`job/pontuar_{presencial,ead}.py`), a do híbrido por fora deste projeto.
