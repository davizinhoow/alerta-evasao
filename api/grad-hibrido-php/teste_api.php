<?php
/*
 * Teste da API PHP do hibrido contra o banco real (somente leitura).
 *
 *     php teste_api.php        (nesta pasta)
 *
 * Conexao: com EVASAO_SQL_SERVER definida, usa as variaveis; sem ela, procura o
 * conexao.php do app-web acima desta pasta -- igual a API.
 *
 * Sobe a API no servidor embutido do PHP (php -S) e chama cada endpoint por
 * HTTP, com a extensao .php (o php -S nao le .htaccess; a URL sem extensao so
 * vale no Apache). Cada resposta e conferida com uma contagem feita direto no
 * SQL, na fila mais recente. Nao depende de numero fixo nem de curso fixo: pega
 * um curso real da fila para os testes de busca. Vale para qualquer dia.
 *
 * PRECISA da tabela TABELA_RISCO_HIBRIDO ja criada e com ao menos uma fila gravada
 * (rode o job/pontuar_hibrido.py --gravar antes).
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

const TABELA = 'BANCO_APP.dbo.TABELA_RISCO_HIBRIDO';
const CURSOS = 'BANCO_ERP.dbo.TABELA_CURSOS';
const ALUNOS = 'BANCO_ERP.dbo.TABELA_ALUNOS';

const LISTAR = '/api/alunos/listar.php';
const CONSULTAR = '/api/alunos/consultar.php';
const RESUMO = '/api/fila/resumo.php';
const DATAS = '/api/fila/datas.php';
const SAUDE = '/api/fila/saude.php';

$falhas = 0;

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

/** Tira acento e sobe para maiuscula -- so para comparar como o collation CI_AI do
 * banco. Mapa fixo em vez de iconv//TRANSLIT, que no Windows gera lixo (ç -> "C~"). */
function semAcento(string $s): string
{
    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
    ];
    return strtoupper(strtr($s, $map));
}

/** A palavra mais longa (>= 4 letras) de um nome de curso, como trecho de busca. */
function palavraChave(string $nome): string
{
    $palavras = preg_split('/[^\p{L}]+/u', $nome, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    usort($palavras, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
    foreach ($palavras as $p) {
        if (mb_strlen($p) >= 4) {
            return $p;
        }
    }
    return $palavras ? $palavras[0] : $nome;
}

/** Sobe "php -S" numa porta livre, com variaveis de ambiente extras. */
function subir(array $env = [])
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $porta = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', __DIR__],
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

function get(string $caminho, array $params = []): array
{
    global $base;
    return http($base . $caminho . q($params));
}

/** Percorre todas as paginas. */
function todos(array $params): array
{
    $out = [];
    for ($pagina = 1; ; $pagina++) {
        $r = get(LISTAR, array_merge($params, [['pagina', $pagina], ['limite', 50]]))[1];
        $out = array_merge($out, $r['alunos']);
        if (count($out) >= $r['total'] || !$r['alunos']) {
            return [$out, $r['total']];
        }
    }
}

/** Mesma regra de api/lib/fila.php: vespera da ultima execucao do calendario (padrao seg/qui, 8h). */
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

[$proc, $base] = subir(['EVASAO_API_ABERTA' => '1']);
$maxDia = sql('SELECT MAX(DT_CORTE) AS d FROM ' . TABELA);
if (!$maxDia || $maxDia[0]['d'] === null) {
    fwrite(STDERR, "tabela TABELA_RISCO_HIBRIDO vazia -- rode o job/pontuar_hibrido.py --gravar antes\n");
    proc_terminate($proc);
    exit(1);
}
$dia = substr((string) $maxDia[0]['d'], 0, 10);
echo 'conexao: ' . (Banco::env('EVASAO_SQL_SERVER') !== '' ? 'variaveis de ambiente' : 'conexao.php do app-web') . "\n";

function sql_n(string $onde, array $params = []): int
{
    global $dia;
    return (int) sql('SELECT COUNT(*) AS n FROM ' . TABELA . ' r LEFT JOIN ' . CURSOS . ' c ON c.CURSO = r.CURSO '
                     . "WHERE r.DT_CORTE = ? {$onde}", array_merge([$dia], $params))[0]['n'];
}

// --- descobre um curso real da fila (preferindo um com acento no nome) --------
$cursosFila = sql('SELECT c.NOME, r.CURSO, COUNT(*) AS n FROM ' . TABELA . ' r JOIN ' . CURSOS
                . " c ON c.CURSO = r.CURSO WHERE r.DT_CORTE = ? AND r.CHANCE_EVASAO = 'CRITICA' "
                . 'GROUP BY c.NOME, r.CURSO ORDER BY n DESC', [$dia]);
if (!$cursosFila) {
    fwrite(STDERR, "fila sem CRITICA -- tabela com fila incompleta?\n");
    proc_terminate($proc);
    exit(1);
}
$escolhido = $cursosFila[0];
foreach ($cursosFila as $cc) {   // prefere um nome com acento, para exercitar o CI_AI
    if (semAcento((string) $cc['NOME']) !== strtoupper((string) $cc['NOME'])) {
        $escolhido = $cc;
        break;
    }
}
$cursoNome = (string) $escolhido['NOME'];
$cursoCod  = (string) $escolhido['CURSO'];
$palavra   = palavraChave($cursoNome);      // trecho do nome, com acento se houver
$palavraSemAc = semAcento($palavra);
$likeSql   = '%' . $palavra . '%';

echo "\nfila/saude\n";
[$st, $r, $cru] = get(SAUDE);
confere('responde 200', $st === 200, substr($cru, 0, 80));
confere('ultima fila = MAX(DT_CORTE) da tabela', $r['ultima_fila'] === $dia, $dia);
$atualizada = $dia >= fila_esperada();
confere('fila_atualizada coerente com a data', $r['fila_atualizada'] === $atualizada,
        "esperada {$r['fila_esperada']}, atraso {$r['dias_de_atraso']} dia(s)");
confere('alunos/listar repete o aviso de atraso', get(LISTAR, [['limite', 1]])[1]['fila_atualizada'] === $atualizada);

echo "\nalunos/listar?curso={$palavra}&faixa=CRITICA  (fila de {$dia})\n";
[$lst, $total] = todos([['curso', $palavra], ['faixa', 'CRITICA']]);
$esperado = sql_n("AND r.CHANCE_EVASAO = 'CRITICA' AND c.NOME LIKE ?", [$likeSql]);
confere('total igual ao SQL direto', $total === $esperado && $esperado === count($lst), "API {$total}, SQL {$esperado}");
confere('todos contem o termo no nome', !array_filter($lst, function ($a) use ($palavraSemAc) {
    return strpos(semAcento((string) $a['nome_curso']), $palavraSemAc) === false;
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
$n1 = get(LISTAR, [['curso', mb_strtoupper($palavra)], ['faixa', 'CRITICA']])[1]['total'];
$n2 = get(LISTAR, [['curso', mb_strtolower($palavra)], ['faixa', 'CRITICA']])[1]['total'];
confere('maiuscula/minuscula nao importa', $n1 === $n2 && $n2 === $total, "{$n1} / {$n2} / {$total}");
$nSem = get(LISTAR, [['curso', $palavraSemAc], ['faixa', 'CRITICA']])[1]['total'];
confere("sem acento ('{$palavraSemAc}') acha o curso", $nSem === $total, "{$nSem} / {$total}");
// ESPECIFICO DO HIBRIDO: a busca por codigo compara os TRES ULTIMOS DIGITOS,
// porque 30 cursos daqui tem dois codigos no ERP academico (2111 e 8111 sao os dois
// "Bacharelado em Nutricao" -- duas autorizacoes no MEC, a 8xxx recebendo os
// ingressantes). A referencia aqui tem que usar a mesma regra; conferir com
// "r.CURSO = ?" exato acusaria falha justamente quando a API acerta.
$nCod = get(LISTAR, [['curso', $cursoCod]])[1]['total'];
$nCodSql = sql_n('AND RIGHT(r.CURSO, 3) = RIGHT(?, 3)', [$cursoCod]);
confere("por codigo (curso={$cursoCod}) = SQL direto por sufixo", $nCod === $nCodSql, "{$nCod} / {$nCodSql}");

// o par so existe se o outro codigo estiver cadastrado; quando existe, os dois
// tem que devolver exatamente a mesma fila
// o par tem que sair da PROPRIA tabela de risco, nao de TABELA_CURSOS inteiro: la o
// sufixo colide com curso de outra modalidade (244 e Estetica no hibrido, mas
// tambem no presencial, e ainda bate com 11244, que e extensao). Dentro da
// tabela do hibrido so existem os pares 2xxx/8xxx -- conferido em 06/10/2026.
$par = sql('SELECT TOP 1 CURSO FROM (SELECT DISTINCT CURSO FROM ' . TABELA . ') t '
           . 'WHERE RIGHT(CURSO, 3) = RIGHT(?, 3) AND CURSO <> ?', [$cursoCod, $cursoCod]);
if ($par) {
    $outro = trim((string) $par[0]['CURSO']);
    $nOutro = get(LISTAR, [['curso', $outro]])[1]['total'];
    confere("o par {$cursoCod}/{$outro} devolve a mesma fila", $nOutro === $nCod, "{$nOutro} / {$nCod}");
    $nExato = sql_n('AND r.CURSO = ?', [$cursoCod]);
    printf("       %s sozinho tem %d na fila; com o par, %d
", $cursoCod, $nExato, $nCod);
} else {
    echo "  --    curso {$cursoCod} nao tem par 2xxx/8xxx -- teste do par pulado
";
}

echo "\nfatores (MOTIVO/MOTIVO2/MOTIVO3 gravados pelo job)\n";
$lstF = get(LISTAR, [['faixa', 'CRITICA'], ['limite', 200]])[1]['alunos'];
$temCampo = true;
$formaOk = true;
foreach ($lstF as $a) {
    if (!array_key_exists('fatores', $a)) {
        $temCampo = false;
        break;
    }
    $f = $a['fatores'];
    // lista de 0 a 3 textos nao vazios -- nunca null, nunca string solta
    if (!is_array($f) || count($f) > 3 || $f !== array_values($f)) {
        $formaOk = false;
    }
    foreach ($f as $t) {
        if (!is_string($t) || trim($t) === '' || mb_strlen($t) > 120) {
            $formaOk = false;
        }
    }
}
confere('todo aluno traz o campo fatores', $temCampo);
confere('fatores e lista de ate 3 textos nao vazios (<=120 chars)', $formaOk);

// tem que bater com o que esta gravado na tabela, para o RA do topo da fila
$raF = (string) $lstF[0]['aluno'];
$linhaF = sql('SELECT MOTIVO, MOTIVO2, MOTIVO3 FROM ' . TABELA . ' WHERE DT_CORTE = ? AND ALUNO = ?',
              [$dia, $raF]);
$esperadoF = [];
foreach (['MOTIVO', 'MOTIVO2', 'MOTIVO3'] as $c) {
    $v = $linhaF[0][$c] ?? null;
    if (is_string($v) && trim($v) !== '') {
        $esperadoF[] = trim($v);
    }
}
confere("fatores do RA {$raF} = colunas MOTIVO* no SQL", $lstF[0]['fatores'] === $esperadoF,
        count($lstF[0]['fatores']) . ' / ' . count($esperadoF));
foreach ($esperadoF as $t) {
    printf("       %s\n", $t);
}

// o mesmo RA pelo /consultar tem que trazer os mesmos fatores
$consF = get(CONSULTAR, [['ra', $raF]])[1];
confere('consultar traz os mesmos fatores do listar', ($consF['fatores'] ?? null) === $esperadoF);
confere('a leitura nao repete os fatores',
        $esperadoF === [] || strpos($consF['leitura'], $esperadoF[0]) === false);

// cobertura: a fila nao pode sair inteira sem motivo (sintoma de job antigo)
$comF = 0;
foreach ($lstF as $a) {
    if ($a['fatores']) {
        $comF++;
    }
}
confere('maioria da CRITICA tem ao menos um fator', $comF > count($lstF) / 2,
        "{$comF} de " . count($lstF));

echo "\nfaixas e posicao\n";
[$crit, $nCrit] = todos([['faixa', 'CRITICA']]);
confere('CRITICA = posicoes 1..N sem buraco', array_column($crit, 'posicao') === range(1, $nCrit), "N={$nCrit}");
$esp = sql_n("AND r.CHANCE_EVASAO IN ('CRITICA','ALTA')");
$duas = get(LISTAR, [['faixa', 'CRITICA'], ['faixa', 'ALTA'], ['limite', 1]])[1]['total'];
confere('varias faixas: faixa=CRITICA&faixa=ALTA', $duas === $esp, (string) $duas);
$duas = get(LISTAR, [['faixa[]', 'CRITICA'], ['faixa[]', 'ALTA'], ['limite', 1]])[1]['total'];
confere('varias faixas: faixa[]=...', $duas === $esp, (string) $duas);
$duas = get(LISTAR, [['faixa', 'critica, alta'], ['limite', 1]])[1]['total'];
confere('varias faixas: faixa=CRITICA,ALTA', $duas === $esp, (string) $duas);

echo "\npaginacao\n";
$p1 = get(LISTAR, [['curso', $palavra], ['faixa', 'CRITICA'], ['limite', 10], ['pagina', 1]])[1];
$p2 = get(LISTAR, [['curso', $palavra], ['faixa', 'CRITICA'], ['limite', 10], ['pagina', 2]])[1];
confere('paginas nao se repetem', !array_intersect(array_column($p1['alunos'], 'aluno'), array_column($p2['alunos'], 'aluno')));
$fim = get(LISTAR, [['curso', $palavra], ['faixa', 'CRITICA'], ['pagina', 999]])[1];
confere('pagina alem do fim: vazia, total certo', $fim['alunos'] === [] && $fim['total'] === $total);

echo "\nentrada digitada a mao\n";
confere("faixa em minusculo ('critica') = CRITICA", get(LISTAR, [['curso', $palavra], ['faixa', 'critica']])[1]['total'] === $total);
confere("'%' e texto, nao curinga", get(LISTAR, [['curso', '%']])[1]['total'] === 0);
confere("'_' e texto, nao curinga", get(LISTAR, [['curso', '_']])[1]['total'] === 0);
confere("'[' e texto, nao curinga", get(LISTAR, [['curso', '[a-z]']])[1]['total'] === 0);
confere("'!' (escape do LIKE) e texto", get(LISTAR, [['curso', '!']])[1]['total'] === 0);
confere('curso so com espacos = sem filtro', get(LISTAR, [['curso', '   '], ['limite', 1]])[1]['total'] === sql_n(''));
confere("aspas nao quebram o SQL", get(LISTAR, [['curso', "x' OR '1'='1"]])[1]['total'] === 0);

echo "\nerros\n";
confere('faixa invalida -> 422', get(LISTAR, [['faixa', 'ALTISSIMA']])[0] === 422);
confere('data sem fila -> 404', get(LISTAR, [['data', '2020-01-01']])[0] === 404);
confere('data impossivel (30/02) -> 422', get(LISTAR, [['data', '2026-02-30']])[0] === 422);
confere('limite 0 / 5000 / abc -> 422', get(LISTAR, [['limite', 0]])[0] === 422
        && get(LISTAR, [['limite', 5000]])[0] === 422 && get(LISTAR, [['limite', 'abc']])[0] === 422);
confere('curso inexistente -> lista vazia', get(LISTAR, [['curso', 'astronautaxyz']])[1]['total'] === 0);
confere('endpoint inexistente -> 404', get('/api/alunos/naoexiste.php')[0] === 404);
confere('POST -> 405', http($base . LISTAR, 'POST')[0] === 405);
[$st, , $cru] = http($base . SAUDE, 'HEAD');
confere('HEAD -> 200 sem corpo', $st === 200 && $cru === '');
[$st, , $cru, $h] = http($base . SAUDE, 'OPTIONS', ['Origin: https://intranet.instituicao.br']);
confere('OPTIONS (CORS) -> 204, origem da intranet liberada', $st === 204
        && (bool) preg_grep('#^Access-Control-Allow-Origin:\s*https://intranet\.instituicao\.br#i', $h));
[, , , $h] = http($base . SAUDE, 'GET', ['Origin: https://site-qualquer.com']);
confere('origem desconhecida nao recebe liberacao CORS', !preg_grep('#^Access-Control-Allow-Origin#i', $h));
[, $r] = get(LISTAR, [['faixa', 'ALTISSIMA']]);
confere('erro no formato do app-web: {"error": ...}', is_array($r) && isset($r['error']) && !isset($r['detail']));

echo "\nJSON e acentos\n";
[$st, $r, $cru, $h] = get(RESUMO, [['curso', $palavra]]);
confere('Content-Type application/json; charset=utf-8', (bool) preg_grep('#^Content-Type:\s*application/json;\s*charset=utf-8#i', $h));
if (semAcento($cursoNome) !== strtoupper($cursoNome)) {
    confere('acento chega certo', $r && isset($r[0]['nome_curso']) && mb_check_encoding($r[0]['nome_curso'], 'UTF-8')
            && strpos($r[0]['nome_curso'], '?') === false, $r[0]['nome_curso'] ?? '');
}
[, $r, $cru] = get(LISTAR, [['limite', 1000]]);
confere('UTF-8 valido, sem caractere trocado', mb_check_encoding($cru, 'UTF-8') && strpos($cru, "\u{FFFD}") === false
        && strpos($cru, '?') === false);

echo "\nfila/resumo e fila/datas\n";
$res = get(RESUMO, [['curso', $palavra]])[1];
confere('resumo: soma da CRITICA = alunos/listar', array_sum(array_column($res, 'critica')) === $total, count($res) . ' cursos');
$d0 = get(DATAS)[1][0];
confere('datas: mais recente primeiro', $d0['dt_corte'] === $dia);
confere('datas: faixas somam o total', $d0['critica'] + $d0['alta'] + $d0['media'] === $d0['total'] && $d0['total'] === sql_n(''),
        "{$d0['critica']}/{$d0['alta']}/{$d0['media']}");

echo "\nalunos/consultar?ra=\n";
$ra = $crit[0]['aluno'];
$h = get(CONSULTAR, [['ra', $ra]])[1];
confere('1o da fila: CRITICA, posicao 1', $h['faixa_ultima_fila'] === 'CRITICA' && $h['historico'][0]['posicao'] === 1
        && $h['historico'][0]['dt_corte'] === $dia, "RA {$ra}");
echo "       leitura: {$h['leitura']}\n";
$fora = sql('SELECT TOP 1 a.ALUNO FROM ' . ALUNOS . " a WHERE a.COL_SITUACAO = 'Ativo' AND NOT EXISTS "
            . '(SELECT 1 FROM ' . TABELA . ' r WHERE r.ALUNO = a.ALUNO)')[0]['ALUNO'];
[$st, $hf] = get(CONSULTAR, [['ra', $fora]]);
confere('RA que existe, fora da fila -> 200, sem faixa', $st === 200 && $hf['faixa_ultima_fila'] === null
        && $hf['historico'] === [], "RA {$fora}");
confere('RA inexistente -> 404 (nao confunde com sem risco)', get(CONSULTAR, [['ra', 'XYZ0000']])[0] === 404);
confere('sem ?ra= -> 422', get(CONSULTAR)[0] === 422);
$comZero = sql('SELECT TOP 1 ALUNO FROM ' . TABELA . " WHERE DT_CORTE = ? AND ALUNO LIKE '0%'", [$dia]);
if ($comZero) {
    $orig = $comZero[0]['ALUNO'];
    [$st, $hz] = get(CONSULTAR, [['ra', ltrim($orig, '0')]]);
    confere('RA sem o zero a esquerda acha o aluno', $st === 200 && $hz['aluno'] === $orig && $hz['faixa_ultima_fila'] !== null,
            ltrim($orig, '0') . " -> {$orig}");
    [$st, $hz] = get(CONSULTAR, [['ra', substr($orig, 0, 3) . '.' . substr($orig, 3, 3) . '-' . substr($orig, 6)]]);
    confere('RA com ponto e traco acha o aluno', $st === 200 && $hz['aluno'] === $orig);
}
$inat = sql('SELECT TOP 1 r.ALUNO, a.COL_SITUACAO FROM ' . TABELA . ' r JOIN ' . ALUNOS . ' a ON a.ALUNO = r.ALUNO '
            . "WHERE r.DT_CORTE = ? AND a.COL_SITUACAO <> 'Ativo'", [$dia]);
if ($inat) {
    $hi = get(CONSULTAR, [['ra', $inat[0]['ALUNO']]])[1];
    confere('na fila mas nao mais Ativo -> leitura avisa', $hi['sit_aluno'] === $inat[0]['COL_SITUACAO']
            && strpos($hi['leitura'], $inat[0]['COL_SITUACAO']) !== false, $hi['leitura']);
}

echo "\ndocs e openapi\n";
[$st, $spec] = get('/api/openapi.php');
confere('openapi valido, com os 5 endpoints', $st === 200 && array_keys($spec['paths'])
        === ['/fila/saude', '/alunos/listar', '/alunos/consultar', '/fila/resumo', '/fila/datas']);
confere('openapi aponta a base da API', ($spec['servers'][0]['url'] ?? '') === '/api', $spec['servers'][0]['url'] ?? '');
[$st, , $cru] = get('/api/docs.php');
confere('docs abre o Swagger', $st === 200 && strpos($cru, 'swagger-ui') !== false && strpos($cru, 'openapi.php') !== false);
proc_terminate($proc);

echo "\nsem token configurado: recusa tudo\n";
// token vazio (como se o config.php tivesse sido apagado). Um espaco, porque o
// Windows remove variavel de ambiente vazia -- a API faz trim e le como vazio.
[$proc, $base] = subir(['EVASAO_API_TOKEN' => ' ', 'EVASAO_API_ABERTA' => '']);
[$st, $r] = get(SAUDE);
confere('sem token e sem EVASAO_API_ABERTA -> 503', $st === 503, $r['error'] ?? '');
confere('dados tambem recusados', get(LISTAR)[0] === 503 && get(CONSULTAR, [['ra', $ra]])[0] === 503);
confere('docs e openapi continuam abertos', get('/api/docs.php')[0] === 200 && get('/api/openapi.php')[0] === 200);
proc_terminate($proc);

echo "\ntoken fixo (api/lib/config.php), sem configurar nada\n";
$fixo = (require __DIR__ . '/api/lib/config.php')['EVASAO_API_TOKEN'] ?? '';
[$proc, $base] = subir(['EVASAO_API_ABERTA' => '']);
confere('config.php tem token fixo', strlen($fixo) >= 20);
confere('sem token -> 401', get(SAUDE)[0] === 401);
confere('com o token fixo -> 200', http($base . SAUDE, 'GET', ["Authorization: Bearer {$fixo}"])[0] === 200);
proc_terminate($proc);

echo "\ntoken (EVASAO_API_TOKEN)\n";
[$proc, $base] = subir(['EVASAO_API_TOKEN' => 'teste123']);
confere('sem token -> 401', get(SAUDE)[0] === 401);
confere('token errado -> 401', http($base . SAUDE, 'GET', ['Authorization: Bearer x'])[0] === 401);
confere('token certo -> 200', http($base . SAUDE, 'GET', ['Authorization: Bearer teste123'])[0] === 200);
confere("'bearer' minusculo -> 200", http($base . SAUDE, 'GET', ['Authorization: bearer teste123'])[0] === 200);
foreach ([LISTAR, CONSULTAR . '?ra=' . $ra, RESUMO, DATAS] as $rota) {
    confere("dados protegidos: {$rota} sem token -> 401", http($base . $rota)[0] === 401);
}
[$st, , , $h] = get(LISTAR);
confere('401 traz WWW-Authenticate: Bearer', (bool) preg_grep('#^WWW-Authenticate:\s*Bearer#i', $h));
confere('docs e openapi continuam abertos', get('/api/docs.php')[0] === 200 && get('/api/openapi.php')[0] === 200);
$esq = get('/api/openapi.php')[1]['components']['securitySchemes'] ?? [];
confere('docs tem botao Authorize (esquema Bearer)', (bool) array_filter($esq, function ($s) { return ($s['scheme'] ?? '') === 'bearer'; }));
proc_terminate($proc);

echo "\n" . ($falhas === 0 ? 'TUDO OK' : "{$falhas} FALHA(S)") . "\n";
exit($falhas === 0 ? 0 : 1);
