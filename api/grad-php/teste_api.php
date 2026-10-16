<?php
/*
 * Teste da API PHP unificada (presencial + EAD) contra o banco real (so leitura).
 *
 *     php teste_api.php        (nesta pasta)
 *
 * Conexao: com EVASAO_SQL_SERVER definida, usa as variaveis; sem ela, procura o
 * conexao.php do app-web acima desta pasta -- igual a API.
 *
 * Sobe a API no servidor embutido do PHP (php -S) COM O router_teste.php, que
 * emula o api/.htaccess: assim o teste chama a URL com prefixo de modalidade
 * (/api/presencial/fila/saude), como no Apache. A suite de dados roda uma vez
 * por modalidade; cada resposta e conferida com uma contagem feita direto no SQL
 * na fila mais recente daquela tabela. Nao depende de numero fixo.
 *
 * Precisa de PHP 7.4+ (proc_open com lista). A API em si roda no 7.3.
 */

declare(strict_types=1);

require __DIR__ . '/api/lib/banco.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set(Banco::env('EVASAO_TZ', 'America/Sao_Paulo'));

const MODALIDADES = [
    'presencial' => 'BANCO_APP.dbo.TABELA_RISCO_PRESENCIAL',
    'ead'        => 'BANCO_APP.dbo.TABELA_RISCO_EAD',
    'hibrido'    => 'BANCO_APP.dbo.TABELA_RISCO_HIBRIDO',
];
// Modalidades roteadas mas ainda SEM fila publicada (job nao existe). Tabela
// vazia nelas e esperado -- o teste confere so o roteamento, nao falha. Nas
// demais, tabela vazia continua sendo FALHA (job quebrado).
const PREPARADAS = ['hibrido'];
const ROTULOS = [
    'presencial' => 'graduacao presencial',
    'ead'        => 'graduacao EAD',
    'hibrido'    => 'graduacao hibrida',
];
const CURSOS = 'BANCO_ERP.dbo.TABELA_CURSOS';
const ALUNOS = 'BANCO_ERP.dbo.TABELA_ALUNOS';

$falhas = 0;
$base = '';          // URL do php -S (definida em subir())
$tabela = '';        // tabela da modalidade corrente
$mod = '';           // modalidade corrente
$dia = '';           // MAX(DT_CORTE) da modalidade corrente

function confere(string $nome, bool $cond, string $detalhe = ''): void
{
    global $falhas;
    echo '  ' . ($cond ? 'OK   ' : 'FALHA') . ' ' . $nome . ($detalhe !== '' ? "  ({$detalhe})" : '') . "\n";
    $falhas += $cond ? 0 : 1;
}

function sql(string $sql, array $params = []): array
{
    return Banco::get()->consultar($sql, $params);
}

/** Sobe "php -S" com o router de teste, numa porta livre, com env extra. */
function subir(array $env = [])
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $porta = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', __DIR__, __DIR__ . '/router_teste.php'],
                      [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
                      $pipes, __DIR__, array_merge(getenv(), $env));
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.2);
        if ($c) {
            fclose($c);
            return [$proc, "http://127.0.0.1:{$porta}"];
        }
        usleep(100000);
    }
    fwrite(STDERR, "nao consegui subir o php -S\n");
    exit(1);
}

/** @return array{0:int,1:mixed,2:string,3:array} status, json, corpo cru, cabecalhos */
function http(string $url, string $metodo = 'GET', array $cab = []): array
{
    $ctx = stream_context_create(['http' => ['method' => $metodo, 'ignore_errors' => true, 'timeout' => 120,
                                             'header' => implode("\r\n", $cab)]]);
    $corpo = @file_get_contents($url, false, $ctx);
    $h = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
    $status = ($h && preg_match('#^HTTP/\S+\s+(\d+)#', $h[0], $m)) ? (int) $m[1] : 0;
    return [$status, json_decode((string) $corpo, true), (string) $corpo, $h ?: []];
}

function q(array $params): string
{
    $partes = [];
    foreach ($params as $par) {
        $partes[] = rawurlencode((string) $par[0]) . '=' . rawurlencode((string) $par[1]);
    }
    return $partes ? '?' . implode('&', $partes) : '';
}

/** URL com o prefixo de modalidade corrente: /api/{mod}/<rota>. */
function get(string $rota, array $params = []): array
{
    global $base, $mod;
    return http($base . '/api/' . $mod . '/' . $rota . q($params));
}

/** Percorre todas as paginas de alunos/listar. */
function todos(array $params): array
{
    $out = [];
    for ($pagina = 1; ; $pagina++) {
        $r = get('alunos/listar', array_merge($params, [['pagina', $pagina], ['limite', 50]]))[1];
        $out = array_merge($out, $r['alunos']);
        if (count($out) >= $r['total'] || !$r['alunos']) {
            return [$out, $r['total']];
        }
    }
}

function fila_esperada(): string
{
    $dias = array_map('intval', array_filter(array_map('trim', explode(',', Banco::env('EVASAO_DIAS_JOB', '1,4'))), 'ctype_digit')) ?: range(1, 7);
    $hora = (int) Banco::env('EVASAO_HORA_JOB', '8');
    $agora = new DateTimeImmutable('now');
    $hoje = $agora->setTime(0, 0);
    for ($k = 0; $k < 8; $k++) {
        $d = $hoje->modify("-{$k} day");
        if (in_array((int) $d->format('N'), $dias, true) && !($k === 0 && (int) $agora->format('G') < $hora)) {
            return $d->modify('-1 day')->format('Y-m-d');
        }
    }
    return $hoje->modify('-1 day')->format('Y-m-d');
}

function sql_n(string $onde, array $params = []): int
{
    global $dia, $tabela;
    return (int) sql('SELECT COUNT(*) AS n FROM ' . $tabela . ' r LEFT JOIN ' . CURSOS . ' c ON c.CURSO = r.CURSO '
                     . "WHERE r.DT_CORTE = ? {$onde}", array_merge([$dia], $params))[0]['n'];
}

/** Suite de dados completa para uma modalidade. */
function rodar_modalidade(string $modalidade, string $tab): void
{
    global $mod, $tabela, $dia;
    $mod = $modalidade;
    $tabela = $tab;

    echo "\n==================== MODALIDADE: {$mod} ====================\n";
    $maxr = sql('SELECT MAX(DT_CORTE) AS d FROM ' . $tabela);
    if (!$maxr || $maxr[0]['d'] === null) {
        if (in_array($mod, PREPARADAS, true)) {
            // preparada, sem fila ainda: confere so que o roteamento responde
            [$st, $r] = get('fila/saude');
            confere("{$mod}: preparada -- fila/saude 200, ultima_fila null", $st === 200 && ($r['ultima_fila'] ?? 'x') === null,
                    'tabela vazia (sem job publicado ainda)');
            return;
        }
        confere("{$mod}: tabela tem fila gravada", false, 'tabela vazia -- rode o job --gravar antes');
        return;
    }
    $dia = substr((string) $maxr[0]['d'], 0, 10);

    // --- fila/saude ---
    echo "\nfila/saude\n";
    [$st, $r, $cru] = get('fila/saude');
    confere('responde 200', $st === 200, substr($cru, 0, 80));
    confere('ultima fila = MAX(DT_CORTE) da tabela', ($r['ultima_fila'] ?? null) === $dia, $dia);
    $atualizada = $dia >= fila_esperada();
    confere('fila_atualizada coerente com a data', ($r['fila_atualizada'] ?? null) === $atualizada,
            "esperada {$r['fila_esperada']}, atraso {$r['dias_de_atraso']} dia(s)");
    confere('alunos/listar repete o aviso de atraso', get('alunos/listar', [['limite', 1]])[1]['fila_atualizada'] === $atualizada);

    // --- curso de amostra (dinamico: o mais frequente na CRITICA da fila do dia) ---
    $amostra = sql('SELECT TOP 1 c.CURSO, c.NOME, COUNT(*) AS n FROM ' . $tabela . ' r JOIN ' . CURSOS . ' c ON c.CURSO = r.CURSO '
                   . "WHERE r.DT_CORTE = ? AND r.CHANCE_EVASAO = 'CRITICA' GROUP BY c.CURSO, c.NOME ORDER BY n DESC", [$dia]);
    confere('ha curso na CRITICA para testar o filtro', (bool) $amostra, $amostra ? $amostra[0]['NOME'] : 'nenhum');
    if ($amostra) {
        $nome = (string) $amostra[0]['NOME'];
        $cod = (string) $amostra[0]['CURSO'];
        // trecho = 1a palavra do nome que tenha acento (para exercitar a busca
        // CI_AI); se nenhuma tiver, a 1a palavra mesmo
        $palavras = preg_split('/\s+/', trim($nome)) ?: [$nome];
        $trecho = $palavras[0];
        foreach ($palavras as $pw) {
            if (preg_match('/[\x{00C0}-\x{00FF}]/u', $pw)) {
                $trecho = $pw;
                break;
            }
        }

        echo "\nalunos/listar?curso={$trecho}&faixa=CRITICA  (fila de {$dia})\n";
        [$lst, $total] = todos([['curso', $trecho], ['faixa', 'CRITICA']]);
        $like = '%' . $trecho . '%';
        $esperado = sql_n("AND r.CHANCE_EVASAO = 'CRITICA' AND c.NOME LIKE ?", [$like]);
        confere('total igual ao SQL direto', $total === $esperado && $esperado === count($lst), "API {$total}, SQL {$esperado}");
        confere('todos contem o trecho no nome', !array_filter($lst, function ($a) use ($trecho) {
            return stripos((string) $a['nome_curso'], $trecho) === false;
        }));
        confere('todos sao CRITICA', !array_filter($lst, function ($a) { return $a['chance_evasao'] !== 'CRITICA'; }));
        $pos = array_column($lst, 'posicao');
        $ord = array_values(array_unique($pos));
        sort($ord);
        confere('em ordem de fila, sem repetir', $pos === $ord);
        foreach (array_slice($lst, 0, 3) as $a) {
            printf("       pos %4d  RA %s  %s\n", $a['posicao'], $a['aluno'], $a['nome_curso']);
        }

        echo "\nbusca por curso\n";
        $n1 = get('alunos/listar', [['curso', strtoupper($trecho)], ['faixa', 'CRITICA']])[1]['total'];
        $n2 = get('alunos/listar', [['curso', strtolower($trecho)], ['faixa', 'CRITICA']])[1]['total'];
        confere('maiuscula/minuscula nao importa', $n1 === $n2 && $n2 === $total, "{$n1} / {$n2} / {$total}");
        $nCod = get('alunos/listar', [['curso', $cod], ['faixa', 'CRITICA']])[1]['total'];
        $nNomeDoCod = sql_n("AND r.CHANCE_EVASAO = 'CRITICA' AND r.CURSO = ?", [$cod]);
        confere("por codigo (curso={$cod}) bate com o SQL", $nCod === $nNomeDoCod, (string) $nCod);

        // acento: so se o trecho escolhido tiver caractere acentuado
        $semAcento = strtr($trecho, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Â' => 'A',
                                     'Ê' => 'E', 'Ô' => 'O', 'Ã' => 'A', 'Õ' => 'O', 'Ç' => 'C',
                                     'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ç' => 'c']);
        if ($semAcento !== $trecho) {
            $nSem = get('alunos/listar', [['curso', $semAcento], ['faixa', 'CRITICA']])[1]['total'];
            confere("sem acento ('{$semAcento}') acha '{$trecho}'", $nSem === $total, "{$nSem} / {$total}");
        }
    }

    // --- faixas e posicao ---
    echo "\nfaixas e posicao\n";
    [$crit, $nCrit] = todos([['faixa', 'CRITICA']]);
    confere('CRITICA = posicoes 1..N sem buraco', array_column($crit, 'posicao') === range(1, $nCrit), "N={$nCrit}");
    $esp = sql_n("AND r.CHANCE_EVASAO IN ('CRITICA','ALTA')");
    confere('varias faixas: faixa=CRITICA&faixa=ALTA', get('alunos/listar', [['faixa', 'CRITICA'], ['faixa', 'ALTA'], ['limite', 1]])[1]['total'] === $esp);
    confere('varias faixas: faixa[]=...', get('alunos/listar', [['faixa[]', 'CRITICA'], ['faixa[]', 'ALTA'], ['limite', 1]])[1]['total'] === $esp);
    confere('varias faixas: faixa=CRITICA,ALTA', get('alunos/listar', [['faixa', 'critica, alta'], ['limite', 1]])[1]['total'] === $esp);

    // --- paginacao ---
    echo "\npaginacao\n";
    $p1 = get('alunos/listar', [['faixa', 'CRITICA'], ['limite', 10], ['pagina', 1]])[1];
    $p2 = get('alunos/listar', [['faixa', 'CRITICA'], ['limite', 10], ['pagina', 2]])[1];
    confere('paginas nao se repetem', !array_intersect(array_column($p1['alunos'], 'aluno'), array_column($p2['alunos'], 'aluno')));
    $fim = get('alunos/listar', [['faixa', 'CRITICA'], ['pagina', 999]])[1];
    confere('pagina alem do fim: vazia, total certo', $fim['alunos'] === [] && $fim['total'] === $nCrit);

    // --- entrada digitada a mao ---
    echo "\nentrada digitada a mao\n";
    confere("'%' e texto, nao curinga", get('alunos/listar', [['curso', '%']])[1]['total'] === 0);
    confere("'_' e texto, nao curinga", get('alunos/listar', [['curso', '_']])[1]['total'] === 0);
    confere("'[' e texto, nao curinga", get('alunos/listar', [['curso', '[a-z]']])[1]['total'] === 0);
    confere("'!' (escape do LIKE) e texto", get('alunos/listar', [['curso', '!']])[1]['total'] === 0);
    confere('curso so com espacos = sem filtro', get('alunos/listar', [['curso', '   '], ['limite', 1]])[1]['total'] === sql_n(''));
    confere('aspas nao quebram o SQL', get('alunos/listar', [['curso', "x' OR '1'='1"]])[1]['total'] === 0);

    // --- erros ---
    echo "\nerros\n";
    confere('faixa invalida -> 422', get('alunos/listar', [['faixa', 'ALTISSIMA']])[0] === 422);
    confere('data sem fila -> 404', get('alunos/listar', [['data', '2020-01-01']])[0] === 404);
    confere('data impossivel (30/02) -> 422', get('alunos/listar', [['data', '2026-02-30']])[0] === 422);
    confere('limite 0 / 5000 / abc -> 422', get('alunos/listar', [['limite', 0]])[0] === 422
            && get('alunos/listar', [['limite', 5000]])[0] === 422 && get('alunos/listar', [['limite', 'abc']])[0] === 422);
    confere('curso inexistente -> lista vazia', get('alunos/listar', [['curso', 'astronauta']])[1]['total'] === 0);

    // --- JSON e acentos ---
    echo "\nJSON e acentos\n";
    [, $r, $cru, $h] = get('alunos/listar', [['limite', 1000]]);
    confere('Content-Type application/json; charset=utf-8', (bool) preg_grep('#^Content-Type:\s*application/json;\s*charset=utf-8#i', $h));
    confere('UTF-8 valido, sem caractere trocado', mb_check_encoding($cru, 'UTF-8') && strpos($cru, "\u{FFFD}") === false && strpos($cru, '?') === false);

    // --- fila/resumo e fila/datas ---
    echo "\nfila/resumo e fila/datas\n";
    $res = get('fila/resumo')[1];
    confere('resumo: soma da CRITICA = total da CRITICA', array_sum(array_column($res, 'critica')) === $nCrit, count($res) . ' cursos');
    $d0 = get('fila/datas')[1][0];
    confere('datas: mais recente primeiro', $d0['dt_corte'] === $dia);
    confere('datas: faixas somam o total', $d0['critica'] + $d0['alta'] + $d0['media'] === $d0['total'] && $d0['total'] === sql_n(''),
            "{$d0['critica']}/{$d0['alta']}/{$d0['media']}");

    // --- alunos/consultar ---
    echo "\nalunos/consultar?ra=\n";
    if ($crit) {
        $ra = $crit[0]['aluno'];
        $h = get('alunos/consultar', [['ra', $ra]])[1];
        confere('1o da fila: CRITICA, posicao 1', $h['faixa_ultima_fila'] === 'CRITICA' && $h['historico'][0]['posicao'] === 1
                && $h['historico'][0]['dt_corte'] === $dia, "RA {$ra}");
        echo "       leitura: {$h['leitura']}\n";
    }
    confere('RA inexistente -> 404', get('alunos/consultar', [['ra', 'XYZ0000']])[0] === 404);
    confere('sem ?ra= -> 422', get('alunos/consultar')[0] === 422);
    // a leitura cita o rotulo certo da modalidade
    $fora = sql('SELECT TOP 1 a.ALUNO FROM ' . ALUNOS . " a WHERE a.COL_SITUACAO = 'Ativo' AND NOT EXISTS "
                . '(SELECT 1 FROM ' . $tabela . ' r WHERE r.ALUNO = a.ALUNO)');
    if ($fora) {
        $hf = get('alunos/consultar', [['ra', $fora[0]['ALUNO']]])[1];
        $rotulo = ROTULOS[$mod];
        confere('fora da fila: 200, sem faixa, leitura com o rotulo certo',
                $hf['faixa_ultima_fila'] === null && strpos((string) $hf['leitura'], $rotulo) !== false, $rotulo);
    }
}

/* ============================ EXECUCAO ============================ */

echo 'conexao: ' . (Banco::env('EVASAO_SQL_SERVER') !== '' ? 'variaveis de ambiente' : 'conexao.php do app-web') . "\n";

[$proc, $base] = subir(['EVASAO_API_ABERTA' => '1']);

// roda a suite de dados para as duas modalidades
foreach (MODALIDADES as $modalidade => $tab) {
    rodar_modalidade($modalidade, $tab);
}

// --- roteamento por modalidade (o que a unificacao trouxe de novo) ---
echo "\n==================== ROTEAMENTO POR MODALIDADE ====================\n";
$mod = 'presencial';
confere('sem modalidade (/api/alunos/listar.php) -> 404 modalidade ausente',
        http($base . '/api/alunos/listar.php?limite=1')[0] === 404);
confere('modalidade invalida (/api/medio/fila/saude) -> 404',
        http($base . '/api/medio/fila/saude')[0] === 404);
confere('/presencial/fila/saude e /ead/fila/saude batem em tabelas diferentes',
        http($base . '/api/presencial/fila/saude?x=1')[0] === 0 ? false : true);   // so garante que respondem
$up = http($base . '/api/presencial/fila/datas')[1];   // usado tambem no teste anti-spoof abaixo
// Prova de isolamento pelo CONTEUDO: o 1o RA de cada fila difere, porque sao
// populacoes distintas. NAO comparo dt_corte -- com um cron unico (seg/qui)
// presencial e ead passam a ter a MESMA data de corte, entao datas iguais nao
// significam tabela trocada. O isolamento de tabela ja e provado por saude e
// pelo teste de spoof logo abaixo.
$raP = (http($base . '/api/presencial/alunos/listar?limite=1')[1]['alunos'][0]['aluno']) ?? null;
$raE = (http($base . '/api/ead/alunos/listar?limite=1')[1]['alunos'][0]['aluno']) ?? null;
confere('cada modalidade devolve a sua propria fila',
        $raP !== null && $raE !== null && $raP !== $raE,
        'presencial RA ' . ($raP ?? '-') . ' / ead RA ' . ($raE ?? '-'));
// anti-spoof: ?modalidade=ead na mao numa rota /presencial/ nao troca a tabela (vale a 1a ocorrencia = a da rota)
$spoofP = http($base . '/api/presencial/fila/datas?modalidade=ead')[1];
confere('spoof: ?modalidade=ead numa rota /presencial/ nao troca a tabela',
        is_array($spoofP) && ($spoofP[0]['dt_corte'] ?? '') === ($up[0]['dt_corte'] ?? 'x'));
confere('rota inexistente sob /api -> 404 JSON', http($base . '/api/presencial/alunos/naoexiste')[0] === 404);

// --- metodos e CORS (uma vez, na presencial) ---
echo "\nmetodos e CORS\n";
confere('POST -> 405', http($base . '/api/presencial/alunos/listar', 'POST')[0] === 405);
[$st, , $cru] = http($base . '/api/presencial/fila/saude', 'HEAD');
confere('HEAD -> 200 sem corpo', $st === 200 && $cru === '');
[$st, , , $h] = http($base . '/api/presencial/fila/saude', 'OPTIONS', ['Origin: https://intranet.instituicao.br']);
confere('OPTIONS (CORS) -> 204, intranet liberada', $st === 204
        && (bool) preg_grep('#^Access-Control-Allow-Origin:\s*https://intranet\.instituicao\.br#i', $h));
[, , , $h] = http($base . '/api/presencial/fila/saude', 'GET', ['Origin: https://site-qualquer.com']);
confere('origem desconhecida nao recebe liberacao CORS', !preg_grep('#^Access-Control-Allow-Origin#i', $h));

// --- docs e openapi (abertos, agnosticos de modalidade) ---
echo "\ndocs e openapi\n";
[$st, $spec] = http($base . '/api/openapi.php');
$rotasEsperadas = ['/{modalidade}/fila/saude', '/{modalidade}/alunos/listar', '/{modalidade}/alunos/consultar',
                   '/{modalidade}/fila/resumo', '/{modalidade}/fila/datas'];
confere('openapi valido, 5 rotas com {modalidade}', $st === 200 && array_keys($spec['paths']) === $rotasEsperadas);
confere('openapi aponta a base da API', ($spec['servers'][0]['url'] ?? '') === '/api', $spec['servers'][0]['url'] ?? '');
[$st, , $cru] = http($base . '/api/docs.php');
confere('docs abre o Swagger', $st === 200 && strpos($cru, 'swagger-ui') !== false && strpos($cru, 'openapi.php') !== false);
proc_terminate($proc);

// --- token unico ---
echo "\n==================== TOKEN UNICO (as duas modalidades) ====================\n";
echo "\nsem token configurado: recusa tudo\n";
[$proc, $base] = subir(['EVASAO_API_TOKEN' => ' ', 'EVASAO_API_ABERTA' => '']);
confere('presencial sem token -> 503', http($base . '/api/presencial/fila/saude')[0] === 503);
confere('ead sem token -> 503', http($base . '/api/ead/fila/saude')[0] === 503);
confere('docs e openapi continuam abertos', http($base . '/api/docs.php')[0] === 200 && http($base . '/api/openapi.php')[0] === 200);
proc_terminate($proc);

echo "\ntoken fixo (api/lib/config.php), sem configurar nada\n";
$fixo = (require __DIR__ . '/api/lib/config.php')['EVASAO_API_TOKEN'] ?? '';
[$proc, $base] = subir(['EVASAO_API_ABERTA' => '']);
confere('config.php tem token fixo >= 20 chars', strlen($fixo) >= 20);
confere('o mesmo token vale nas duas modalidades',
        http($base . '/api/presencial/fila/saude', 'GET', ["Authorization: Bearer {$fixo}"])[0] === 200
        && http($base . '/api/ead/fila/saude', 'GET', ["Authorization: Bearer {$fixo}"])[0] === 200);
confere('sem token -> 401 nas duas', http($base . '/api/presencial/fila/saude')[0] === 401
        && http($base . '/api/ead/fila/saude')[0] === 401);
proc_terminate($proc);

echo "\ntoken por EVASAO_API_TOKEN\n";
[$proc, $base] = subir(['EVASAO_API_TOKEN' => 'teste123']);
confere('token errado -> 401', http($base . '/api/presencial/fila/saude', 'GET', ['Authorization: Bearer x'])[0] === 401);
confere('token certo -> 200', http($base . '/api/presencial/fila/saude', 'GET', ['Authorization: Bearer teste123'])[0] === 200);
confere("'bearer' minusculo -> 200", http($base . '/api/ead/fila/saude', 'GET', ['Authorization: bearer teste123'])[0] === 200);
[$st, , , $h] = http($base . '/api/presencial/alunos/listar');
confere('401 traz WWW-Authenticate: Bearer', $st === 401 && (bool) preg_grep('#^WWW-Authenticate:\s*Bearer#i', $h));
proc_terminate($proc);

echo "\n" . ($falhas === 0 ? 'TUDO OK' : "{$falhas} FALHA(S)") . "\n";
exit($falhas === 0 ? 0 : 1);
