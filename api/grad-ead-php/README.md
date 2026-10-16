# API de consulta — risco de evasão, Graduação EAD (PHP)

Gêmea PHP da [`../grad-presencial-php/`](../grad-presencial-php/): mesmo desenho, mesmo
**formato das APIs do app-web** (`agendamento-new/api`) — um arquivo por endpoint,
`cors.php` com `setCors()` / `json()` / `error()`, `lib/` com as regras, URL sem extensão,
conexão pelo `conexao.php` do app-web. Só muda a **tabela** que lê e os textos.

```
integrador (seg e qui, 06:30) → job/pontuar_ead.py → TABELA_RISCO_EAD → esta API
```

**A API só lê.** Não roda modelo nem grava nada: quem pontua é o `job/pontuar_ead.py`,
que roda a query `querys/12_job_ead_periodico.sql` e aplica o modelo mensal
(`ml/grad-ead/modelo_evasao_ead_mensal.pkl`).

**Vai consumir a API?** Veja o [COMO_USAR.md](COMO_USAR.md) — rotas, exemplos e as regras para o assistente.

## Estrutura

```
api_evasao_grad_ead/                       (esta pasta, na raiz do app-web)
├── .gitignore                    ignora api/lib/config.local.php
├── .htaccess                     bloqueia o teste e os .md
├── README.md                     instalar e manter
├── COMO_USAR.md                  consumir a API
├── teste_api.php                 teste contra o banco real
└── api/
    ├── .htaccess                 URL sem extensão + 404 em JSON + repasse do token
    ├── cors.php                  setCors(), apenasGet(), json(), error()
    ├── auth.php                  requireToken()
    ├── alunos/
    │   ├── listar.php            alunos em risco, com filtro
    │   └── consultar.php         situação de um aluno (?ra=)
    ├── fila/
    │   ├── resumo.php            contagem por curso e faixa
    │   ├── datas.php             filas gravadas
    │   └── saude.php             o banco responde? a fila é a esperada?
    ├── openapi.php               especificação (para o assistente) — aberta
    ├── docs.php                  Swagger — aberto
    ├── nao_encontrado.php        404 em JSON para rota inexistente
    └── lib/                      bloqueada pelo .htaccess
        ├── banco.php             conexão: conexao.php do app-web ou variáveis
        ├── config.php            token fixo — VERSIONADO, é o que todo mundo usa
        ├── config.exemplo.php    modelo do config.local.php (opcional)
        ├── fila.php              validação, datas, filtro de curso, consulta da fila
        └── openapi.php           a especificação — mude junto se mexer num endpoint
```

O código é **idêntico** ao do presencial-php; a única diferença em `lib/fila.php` é
`const TABELA = ...TABELA_RISCO_EAD`. Mexeu num endpoint aqui? Reflita no presencial, e
vice-versa.

## Endpoints (GET)

Base: `https://servidor/api_evasao_grad_ead/api`

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
configurável). **Quem consome tem que conferir esse campo.**

- `curso`: código (`7xxx`) ou trecho do nome (`pedagogia`, `administração`) — acento e
  maiúscula não importam.
- `faixa`: `CRITICA`, `ALTA`, `MEDIA`, em maiúscula ou não. Várias: `faixa=CRITICA&faixa=ALTA`,
  `faixa[]=...` ou `faixa=CRITICA,ALTA`.
- `posicao`: posição na **fila geral do dia** (todos os cursos), não dentro do filtro.
- Aluno fora da tabela = **sem risco** naquele dia (o job só grava as três faixas).

```
alunos/listar?curso=pedagogia&faixa=CRITICA           pedagogia com chance crítica
alunos/listar?faixa=CRITICA&data=2026-07-30           a CRITICA inteira de um dia
fila/resumo?curso=administracao                       quantos por curso e faixa
alunos/consultar?ra=7201234                            situação de um aluno
```

### `alunos/consultar` — três respostas, sem ambiguidade

| Caso | Resposta |
|---|---|
| RA não existe no ERP academico | **404** |
| existe, fora das faixas na fila mais recente | 200, `faixa_ultima_fila: null` |
| está numa faixa | 200, `faixa_ultima_fila: "CRITICA"` (ou `ALTA`, `MEDIA`) |
| sem `?ra=` | **422** |

"Fora das faixas" cobre os ~80% de menor risco **e** quem o job nem pontua (outra
modalidade, curso encerrado). O campo `leitura` diz isso em uma frase. `sit_aluno` é a
situação **atual** no ERP academico — o aluno pode estar na fila de ontem e já ter cancelado hoje.

### Erros

No formato do app-web: `{"error": "mensagem"}`.

| Código | Quando |
|---|---|
| 401 | sem token ou token errado (traz `WWW-Authenticate: Bearer`) |
| 404 | rota inexistente (lista as válidas); não há fila para a `data` pedida; RA inexistente |
| 405 | método diferente de GET |
| 422 | parâmetro inválido (faixa, data, limite, `ra` ausente) |
| 503 | banco indisponível (o detalhe vai para o log do PHP, não para a resposta) |

### Faixas e operação do EAD

Pela posição na fila do dia, não pela probabilidade (o modelo não é calibrado):

| Faixa | Regra | Tamanho (base ~9.600) | Ação da equipe |
|---|---|---|---|
| `CRITICA` | 20% do topo da ALTA (≈ 2% da base) | ~190 | contato mais direto |
| `ALTA` | resto dos 10% do topo | ~770 | notificação/mensagem |
| `MEDIA` | de 10% a 20% da base | ~960 | **nada** — gravada só para histórico |

**No EAD a equipe notifica os 10% (faixa ALTA), não uma fila de 200.** A métrica que
manda é `recall@10%`: dos evasores reais, cerca de **3 a 4 em cada 10** caem no topo 10%
da fila (0,30 no corte de 30 dias, 0,42 no fechamento; coorte de teste 2026/72). A
`MEDIA` continua na tabela para medir depois se valeria a pena ter falado com esse grupo,
mas **não entra em disparo**.

## Quando o job pontua (calendário do EAD)

Diferente do presencial (semestre único), o EAD é **trimestral** (períodos 71–74). O job
pontua a coorte do trimestre corrente, e escolhe o modelo pela **profundidade do corte**:

| Corte | Quando | Modelo |
|---|---|---|
| m1 | a partir de 30 dias do início do trimestre | usa features acadêmicas + financeiras (sem AVA) |
| m2 | a partir de 60 dias | idem |
| m3 | no fechamento do trimestre (`DT_FIM`) | acrescenta o comportamento no AVA |

Nos **primeiros 30 dias** de cada trimestre o job não pontua (a coorte ainda está se
formando, como o "julho morto" do presencial) — `fila/datas` simplesmente não terá fila
para esses dias. Ver o cabeçalho de `querys/12_job_ead_periodico.sql`.

## Instalar no app-web

1. Copiar esta pasta para a raiz do app-web como `api_evasao_grad_ead/` (ao lado do
   `conexao.php`). Ela não tem senha nenhuma — pode ser commitada.
2. **Nada a configurar.** O token é **fixo e versionado** em `api/lib/config.php` (é
   **diferente** do token do presencial). Quem baixa a pasta já consegue testar.
   Consequência: o token barra quem está de fora (internet), mas **qualquer pessoa com
   acesso ao repositório o conhece**.

   Usuário, senha e servidor vêm do `conexao.php` (`Conexao::getConnection('instituicao')` =
   `BANCO_APP`), então quando a senha mudar lá a API acompanha.

   **Para trocar o token num servidor** sem mexer no código: copiar
   `api/lib/config.exemplo.php` para `api/lib/config.local.php` e preencher
   `EVASAO_API_TOKEN`. Fica **fora do git**, é **bloqueado na web** e **vale mais** que o
   `config.php`. `SetEnv EVASAO_API_TOKEN` no VirtualHost vale mais que os dois.

   **Sem token nenhum a API recusa tudo** (`503`). Teste rápido depois de todo deploy:
   `fila/saude` sem token deve dar `401`.
3. Abrir `/api_evasao_grad_ead/api/docs`, clicar em **Authorize**, colar o token e testar.

O `conexao.php` fica na raiz do app-web, **não é versionado** e é usado por ~4 mil
arquivos do repo. A API o encontra subindo pelas pastas.

## Fora do app-web: variáveis de ambiente

Com `EVASAO_SQL_SERVER` definida, a API ignora o `conexao.php`. As mesmas variáveis do
presencial-php valem aqui (a conexão é o mesmo `BANCO_APP`):

| Variável | |
|---|---|
| `EVASAO_SQL_SERVER` | servidor do banco. Sem ela, a API usa o `conexao.php` do app-web |
| `EVASAO_SQL_USER` / `EVASAO_SQL_PASSWORD` | login SQL. Sem eles, o login Windows do processo |
| `EVASAO_API_TOKEN` | toda chamada (menos `docs` e `openapi`) exige este token. Padrão: o fixo de `api/lib/config.php` |
| `EVASAO_API_ABERTA` | `1` abre a API sem token — só para teste local, nunca em servidor |
| `EVASAO_PDO_DRIVER` | `auto` (padrão: `sqlsrv` se existir, senão `odbc`), `sqlsrv` ou `odbc` |
| `EVASAO_SQL_DRIVER` | só `pdo_odbc`: padrão `ODBC Driver 17 for SQL Server` |
| `EVASAO_SQL_TRUST_CERT` | `1` (padrão) aceita o certificado do servidor |
| `EVASAO_ODBC_CHARSET` | só `pdo_odbc`: padrão `Windows-1252` no Windows, `UTF-8` fora |
| `EVASAO_DIAS_JOB` | dias em que o job roda, ISO (1 = segunda ... 7 = domingo). Padrão `1,4` (seg e qui) |
| `EVASAO_HORA_JOB` | hora em que o job já terminou (padrão `8`); antes dela, vale a execução anterior |
| `EVASAO_TZ` | fuso para calcular o atraso da fila (padrão `America/Sao_Paulo`) |

## Requisitos

- **PHP 7.3 ou mais novo** — o app-web roda 7.3. O `teste_api.php` precisa de 7.4+.
- `pdo_sqlsrv` (o app-web já tem) ou `pdo_odbc`.
- Apache com `mod_rewrite` e `AllowOverride All`. Sem reescrita, as URLs funcionam com
  `.php`: `api/alunos/listar.php`.

## Testar

```powershell
cd api/grad-ead-php
$env:EVASAO_SQL_SERVER = "servidor"      # ou rode dentro do app-web, sem variável
php teste_api.php
```

Sobe a API no servidor embutido do PHP e chama cada endpoint por HTTP, conferindo com
contagem feita direto no SQL: busca por nome (com e sem acento), por código, faixas nos
três formatos, paginação, caracteres especiais e aspas, erros (401, 404, 405, 422), CORS,
acentos no JSON, os casos de `alunos/consultar`, `docs`, `openapi` e token. **Precisa da
tabela `TABELA_RISCO_EAD` já criada e com ao menos uma fila gravada** (rode o
`job/pontuar_ead.py --gravar` antes).

## Diferenças em relação ao presencial

Só a tabela de origem (`TABELA_RISCO_EAD`), o token, os textos e os exemplos de curso. O
contrato das rotas, os formatos de resposta e os códigos de erro são os mesmos — um
cliente escrito para uma serve a outra trocando a base da URL e o token.
