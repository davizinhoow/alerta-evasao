# API de consulta — risco de evasão, Graduação Presencial (PHP)

Versão PHP de [`../grad-presencial/`](../grad-presencial/) (FastAPI), no **formato das APIs
do app-web** (`agendamento-new/api`): um arquivo por endpoint, `cors.php` com
`setCors()` / `json()` / `error()`, `lib/` com as regras, URL sem extensão, conexão pelo
`conexao.php` do app-web.

```
integrador (seg e qui, 06:30) → job/pontuar_presencial.py → TABELA_RISCO_PRESENCIAL → esta API
```

**A API só lê.** Não roda modelo nem grava nada.

**Vai consumir a API?** Veja o [COMO_USAR.md](COMO_USAR.md) — rotas, exemplos e as regras para o assistente.

## Estrutura

```
api_evasao_grad_presencial/                (esta pasta, na raiz do app-web)
├── .gitignore                    ignora api/lib/config.local.php
├── .htaccess                     bloqueia o teste e os .md
├── README.md                     instalar e manter
├── COMO_USAR.md                  consumir a API
├── teste_api.php                 teste contra o banco real
└── api/
    ├── .htaccess                 URL sem extensão + 404 em JSON + repasse do token (ver nota abaixo)
    ├── cors.php                  setCors(), apenasGet(), json(), error()
    ├── auth.php                  requireToken()
    ├── alunos/
    │   ├── listar.php            alunos em risco, com filtro
    │   └── consultar.php         situação de um aluno (?ra=)
    ├── fila/
    │   ├── resumo.php            contagem por curso e faixa
    │   ├── datas.php             filas gravadas
    │   └── saude.php             o banco responde? a fila é a de hoje?
    ├── openapi.php               especificação (para o assistente) — aberta
    ├── docs.php                  Swagger — aberto
    ├── nao_encontrado.php        404 em JSON para rota inexistente (o .htaccess manda para cá)
    └── lib/                      bloqueada pelo .htaccess
        ├── banco.php             conexão: conexao.php do app-web ou variáveis
        ├── config.php            token fixo — VERSIONADO, é o que todo mundo usa
        ├── config.exemplo.php    modelo do config.local.php (opcional)
        ├── config.local.php      opcional: troca o token neste servidor — fora do git
        ├── fila.php              validação, datas, filtro de curso, consulta da fila
        └── openapi.php           a especificação — mude junto se mexer num endpoint
```

Cada endpoint segue o molde do app-web:

```php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/fila.php';

setCors();
apenasGet();
requireToken();
...
json([...]);
```

**`.htaccess` da `api/`:** parte do do `agendamento-new`, com uma correção — o original
confere `%{REQUEST_FILENAME}.php`, que o Apache corta num segmento a mais
(`alunos/consultar/123` vira `alunos/consultar`) e entra em loop: erro 500. Aqui a
conferência usa a URL inteira (`%{DOCUMENT_ROOT}%{REQUEST_URI}.php`) e a flag `[END]`.
O mesmo defeito existe no `agendamento-new/api/.htaccess`.

**Autenticação:** `requireToken()` em vez do `requireAuth()` (JWT) do `agendamento-new`.
Lá quem chama é um funcionário logado na intranet; aqui é o assistente com IA, de
servidor para servidor. Token fixo no cabeçalho `Authorization: Bearer <token>`.

## Endpoints (GET)

Base: `https://servidor/api_evasao_grad_presencial/api`

| Endpoint | O que devolve |
|---|---|
| `alunos/listar` | alunos em risco — filtros `curso`, `faixa`, `data`, `pagina`, `limite` |
| `alunos/consultar?ra=` | situação do aluno na fila mais recente + histórico (`dias`) |
| `fila/resumo` | contagem por curso e faixa — filtros `curso`, `data` |
| `fila/datas` | filas gravadas, com o total de cada faixa (`limite`) |
| `fila/saude` | o banco responde e se a fila está atrasada |
| `docs` | documentação interativa — **Authorize** para colar o token |
| `openapi` | especificação OpenAPI (é o que o assistente lê) |

Sem `data`, tudo usa a **fila mais recente**. Toda resposta de fila traz
`fila_atualizada`: `false` = não é a fila da última execução do job (segunda e quinta,
configurável — ver abaixo). **Quem consome tem que conferir esse campo.**

- `curso`: código (`258`) ou trecho do nome (`engenharia`, `mecanica`) — acento e
  maiúscula não importam.
- `faixa`: `CRITICA`, `ALTA`, `MEDIA`, em maiúscula ou não. Várias: `faixa=CRITICA&faixa=ALTA`,
  `faixa[]=...` ou `faixa=CRITICA,ALTA`.
- `posicao`: posição na **fila geral do dia** (todos os cursos), não dentro do filtro.
- Aluno fora da tabela = **sem risco** naquele dia (o job só grava as três faixas).

```
alunos/listar?curso=engenharia&faixa=CRITICA          engenharias com chance crítica
alunos/listar?curso=258&faixa=CRITICA&faixa=ALTA      Eng. Mecânica, crítica + alta
alunos/listar?faixa=CRITICA&data=2026-09-23           a CRITICA inteira de um dia
fila/resumo?curso=engenharia                          quantos por curso e faixa
alunos/consultar?ra=9000001                           situação de um aluno
```

### `alunos/consultar` — três respostas, sem ambiguidade

| Caso | Resposta |
|---|---|
| RA não existe no ERP academico | **404** |
| existe, fora das faixas na fila mais recente | 200, `faixa_ultima_fila: null` |
| está numa faixa | 200, `faixa_ultima_fila: "CRITICA"` (ou `ALTA`, `MEDIA`) |
| sem `?ra=` | **422** |

"Fora das faixas" cobre os ~80% de menor risco **e** quem o job nem pontua. O campo
`leitura` diz isso em uma frase. `sit_aluno` é a situação **atual** no ERP academico — o aluno
pode estar na fila de ontem e já ter cancelado hoje.

### Erros

No formato do app-web: `{"error": "mensagem"}`.

| Código | Quando |
|---|---|
| 401 | sem token ou token errado (traz `WWW-Authenticate: Bearer`) |
| 404 | rota inexistente (lista as válidas); não há fila para a `data` pedida; RA inexistente |
| 405 | método diferente de GET |
| 422 | parâmetro inválido (faixa, data, limite, `ra` ausente) |
| 503 | banco indisponível (o detalhe vai para o log do PHP, não para a resposta) |

### Faixas

Pela posição na fila do dia, não pela probabilidade (o modelo não é calibrado):

| Faixa | Regra | Tamanho (base de 9.429) | Quantos evadem* |
|---|---|---|---|
| `CRITICA` | 20% do topo da ALTA (≈ 2% da base) | 189 | ~41% |
| `ALTA` | resto dos 10% do topo | 754 | ~25% |
| `MEDIA` | de 10% a 20% da base | 943 | ~16% |

\* média dos 5 meses, CV out-of-fold na coorte 2025/2; a média da base é 8,2%.

## Instalar no app-web

1. Copiar esta pasta para a raiz do app-web como `api_evasao_grad_presencial/` (ao lado do
   `conexao.php`). Ela não tem senha nenhuma — pode ser commitada.
2. **Nada a configurar.** O token é **fixo e versionado** em `api/lib/config.php` — quem
   baixa a pasta já consegue testar. Decisão de 25/09/2026, para facilitar os testes da
   equipe. Consequência: o token barra quem está de fora (internet), mas **qualquer
   pessoa com acesso ao repositório app-web o conhece**.

   Usuário, senha e servidor vêm do `conexao.php` (`Conexao::getConnection('instituicao')`),
   então quando a senha mudar lá a API acompanha.

   **Para trocar o token num servidor** (recomendado em produção, se o repositório tiver
   acesso amplo), sem mexer no código: copiar `api/lib/config.exemplo.php` para
   `api/lib/config.local.php` e preencher `EVASAO_API_TOKEN`. Esse arquivo fica **fora do
   git**, é **bloqueado na web** e **vale mais** que o `config.php`. `SetEnv
   EVASAO_API_TOKEN` no VirtualHost vale mais que os dois.

   **Sem token nenhum a API recusa tudo** (`503`). Antes ela ficava aberta — foi o que
   aconteceu no primeiro deploy em `app.instituicao.br` (25/09/2026), quando o token ainda
   não era versionado. Teste rápido depois de todo deploy: `fila/saude` sem token deve
   dar `401`.
3. Abrir `/api_evasao_grad_presencial/api/docs`, clicar em **Authorize**, colar o token e testar.

O `conexao.php` fica na raiz do app-web, **não é versionado** (cada ambiente tem o seu) e
é usado por ~4 mil arquivos do repo. A API o encontra subindo pelas pastas.

## Fora do app-web: variáveis de ambiente

Com `EVASAO_SQL_SERVER` definida, a API ignora o `conexao.php`. Qualquer uma destas
pode vir da variável de ambiente, do `api/lib/config.local.php` ou do `api/lib/config.php`, nesta ordem:

| Variável | |
|---|---|
| `EVASAO_SQL_SERVER` | servidor do banco. Sem ela, a API usa o `conexao.php` do app-web |
| `EVASAO_SQL_USER` / `EVASAO_SQL_PASSWORD` | login SQL. Sem eles, o login Windows do processo |
| `EVASAO_API_TOKEN` | toda chamada (menos `docs` e `openapi`) exige este token. Padrão: o fixo de `api/lib/config.php`. **Sem token nenhum a API recusa tudo (503)** |
| `EVASAO_API_ABERTA` | `1` abre a API sem token — só para teste local, nunca em servidor |
| `EVASAO_PDO_DRIVER` | `auto` (padrão: `sqlsrv` se existir, senão `odbc`), `sqlsrv` ou `odbc` |
| `EVASAO_SQL_DRIVER` | só `pdo_odbc`: padrão `ODBC Driver 17 for SQL Server` |
| `EVASAO_SQL_TRUST_CERT` | `1` (padrão) aceita o certificado do servidor (o ODBC Driver 18 exige criptografia) |
| `EVASAO_ODBC_CHARSET` | só `pdo_odbc`: padrão `Windows-1252` no Windows, `UTF-8` fora |
| `EVASAO_DIAS_JOB` | dias em que o job roda, ISO (1 = segunda ... 7 = domingo). Padrão `1,4` (seg e qui) |
| `EVASAO_HORA_JOB` | hora em que o job já terminou (padrão `8`); antes dela, vale a execução anterior |
| `EVASAO_TZ` | fuso para calcular o atraso da fila (padrão `America/Sao_Paulo`) |

## Requisitos

- **PHP 7.3 ou mais novo** — o app-web roda 7.3. O `teste_api.php` precisa de 7.4+.
- `pdo_sqlsrv` (o app-web já tem) ou `pdo_odbc`.
- Apache com `mod_rewrite` e `AllowOverride All` (o app-web já tem). Sem reescrita, as
  URLs funcionam com `.php`: `api/alunos/listar.php`.

## Testar

```powershell
cd api/grad-presencial-php
$env:EVASAO_SQL_SERVER = "servidor"      # ou rode dentro do app-web, sem variável
php teste_api.php
```

Sobe a API no servidor embutido do PHP e chama cada endpoint por HTTP, conferindo com
contagem feita direto no SQL: busca por nome (com e sem acento), por código, faixas nos
três formatos, paginação, caracteres especiais e aspas, erros (401, 404, 405, 422),
CORS, acentos no JSON, os casos de `alunos/consultar`, `docs`, `openapi` e token.

**Validado em 25/09/2026:**
- bateria completa nos dois modos de conexão (variáveis e `conexao.php`);
- Apache numa réplica do app-web (`conexao.php` na raiz, API em `api_evasao_grad_presencial/`,
  URL sem extensão, só o token definido): `lib/`, teste e README bloqueados (403);
- **151 de 151 respostas idênticas à versão Python** (34 cursos, 46 RAs, paginação, erros).

- **no container do app-web** (`api_evasao_grad_presencial/`, PHP 7.3.33 + `pdo_sqlsrv`,
  Apache 2.4.52, conexão pelo `conexao.php`): todos os endpoints, erros, CORS e bloqueios
  certos, sem nada no log, e de novo **151 de 151 idênticas à Python**.

## Diferenças em relação à versão Python

| Python | PHP |
|---|---|
| `/alunos` | `api/alunos/listar` |
| `/alunos/{ra}` | `api/alunos/consultar?ra=` |
| `/resumo` · `/datas` · `/saude` | `api/fila/resumo` · `api/fila/datas` · `api/fila/saude` |
| `/docs` · `/openapi.json` | `api/docs` · `api/openapi` |
| erro `{"detail": ...}` | erro `{"error": ...}` (padrão do app-web) |

As respostas de sucesso são idênticas. A PHP aceita também `faixa[]=` e `faixa=A,B`,
conta parâmetro vazio (`?data=`) como ausente e tem CORS para a intranet.
