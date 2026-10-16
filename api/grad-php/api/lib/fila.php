<?php
/*
 * Regras compartilhadas pelos endpoints: leitura e validacao dos parametros,
 * datas da fila, filtro de curso e a consulta base da fila do dia.
 */

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/banco.php';

date_default_timezone_set(Banco::env('EVASAO_TZ', 'America/Sao_Paulo'));

// Uma API, as modalidades. A tabela e o rotulo saem do 1o segmento da URL
// (/presencial/..., /ead/... ou /hibrido/...), que o .htaccess repassa como
// ?modalidade=. Cada modalidade tem a sua tabela TABELA_RISCO_*; quem grava
// (modelo + job) e outro, mas as 5 colunas sao identicas, por isso cabe uma API
// so. Para somar uma modalidade: uma linha em cada mapa + o alias no .htaccess e
// no router_teste.php.
//
// HIBRIDO: roteado e EM USO -- a TABELA_RISCO_HIBRIDO ja tem fila (job de um
// colega) e MOTIVO* preenchido, entao /hibrido/... entra em MODALIDADES_COM_MOTIVO
// (fatores.php) como as outras. O modelo oficial nao foi publicado (02/10/2026:
// empata com a regra de uma coluna), mas o job que grava a tabela existe. Unica
// diferenca de regra: o filtro de curso compara pelos 3 ultimos digitos (par
// 2xxx/8xxx = mesmo curso) -- ver filtro_curso().
const MODALIDADES = [
    'presencial' => 'BANCO_APP.dbo.TABELA_RISCO_PRESENCIAL',
    'ead'        => 'BANCO_APP.dbo.TABELA_RISCO_EAD',
    'hibrido'    => 'BANCO_APP.dbo.TABELA_RISCO_HIBRIDO',
];
const ROTULOS = [
    'presencial' => 'graduacao presencial',
    'ead'        => 'graduacao EAD',
    'hibrido'    => 'graduacao hibrida',
];
const CURSOS = 'BANCO_ERP.dbo.TABELA_CURSOS';
const ALUNOS = 'BANCO_ERP.dbo.TABELA_ALUNOS';
const FAIXAS = ['CRITICA', 'ALTA', 'MEDIA'];

/**
 * A modalidade pedida, lida do 1o segmento da URL. O .htaccess injeta
 * ?modalidade= ANTES da query do usuario, entao vale a PRIMEIRA ocorrencia --
 * quem repetir ?modalidade= na mao nao consegue trocar a tabela definida pela
 * rota. Ausente ou fora do mapa: 404.
 */
function modalidade(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $achado = '';
    foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $par) {
        $kv = explode('=', $par, 2);
        if (urldecode($kv[0]) === 'modalidade') {
            $achado = strtolower(trim(urldecode($kv[1] ?? '')));
            break;
        }
    }
    if (!array_key_exists($achado, MODALIDADES)) {
        error('modalidade ausente ou invalida -- chame /presencial/..., /ead/... ou /hibrido/... (ex.: presencial/fila/saude)', 404);
    }
    $cache = $achado;
    return $cache;
}

/** Tabela TABELA_RISCO_* da modalidade pedida. */
function tabela(): string
{
    return MODALIDADES[modalidade()];
}

/** Rotulo da modalidade para as mensagens ("graduacao presencial" / "graduacao EAD"). */
function rotulo(): string
{
    return ROTULOS[modalidade()];
}

// Calendario do job no integrador (EVASAO_DIAS_JOB / EVASAO_HORA_JOB, no ambiente
// ou no config.local.php): em quais dias ele roda (ISO: 1 = segunda ... 7 = domingo)
// e ate que hora ja terminou. Cada execucao grava DT_CORTE = vespera.
// Padrao: segunda e quinta, pronto as 8h (roda ~06:30).
const DIAS_JOB_PADRAO = '1,4';
const HORA_JOB_PADRAO = 8;

// A posicao sai do ID: o job insere a fila do dia em ordem, do maior risco para
// o menor. E calculada ANTES do filtro, entao e a posicao na fila geral.
// Funcao (nao const) porque a tabela so e conhecida em tempo de execucao, pela
// modalidade da URL.
function fila_cte(): string
{
    return '
WITH fila AS (
    SELECT r.DT_CORTE, r.ALUNO, r.CHANCE_EVASAO, r.CURSO, c.NOME AS NOME_CURSO,
           r.MOTIVO, r.MOTIVO2, r.MOTIVO3,
           ROW_NUMBER() OVER (ORDER BY r.ID) AS POSICAO
    FROM ' . tabela() . ' r
    LEFT JOIN ' . CURSOS . ' c ON c.CURSO = r.CURSO
    WHERE r.DT_CORTE = ?
)
';
}

const SOMA_FAIXAS = "
           SUM(CASE WHEN CHANCE_EVASAO = 'CRITICA' THEN 1 ELSE 0 END) AS critica,
           SUM(CASE WHEN CHANCE_EVASAO = 'ALTA'    THEN 1 ELSE 0 END) AS alta,
           SUM(CASE WHEN CHANCE_EVASAO = 'MEDIA'   THEN 1 ELSE 0 END) AS media,
           COUNT(*) AS total";


function consultar(string $sql, array $params = []): array
{
    try {
        return Banco::get()->consultar($sql, $params);
    } catch (ErroBanco $e) {
        // a mensagem do driver vai para o log, nao para quem chamou (tem nome de servidor)
        error_log('api evasao: ' . $e->getMessage());
        error('banco indisponivel', 503);
    }
    return [];   // inalcancavel: error() encerra
}

/**
 * Le a query string a mao: o $_GET do PHP guarda so o ultimo de
 * "faixa=CRITICA&faixa=ALTA". Aceita tambem "faixa[]=".
 *
 * @return array<string, array<int, string>>
 */
function parametros(): array
{
    $saida = [];
    foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $par) {
        if ($par === '') {
            continue;
        }
        $kv = explode('=', $par, 2);
        $nome = urldecode($kv[0]);
        if (substr($nome, -2) === '[]') {
            $nome = substr($nome, 0, -2);
        }
        $saida[$nome][] = isset($kv[1]) ? urldecode($kv[1]) : '';
    }
    return $saida;
}

/** Valor unico (o ultimo); vazio conta como ausente. */
function p_texto(array $q, string $nome): ?string
{
    if (!isset($q[$nome])) {
        return null;
    }
    $v = end($q[$nome]);
    return trim($v) === '' ? null : $v;
}

function p_inteiro(array $q, string $nome, int $padrao, int $min, int $max): int
{
    $v = p_texto($q, $nome);
    if ($v === null) {
        return $padrao;
    }
    $v = trim($v);
    if (!preg_match('/^\d{1,9}$/', $v) || (int) $v < $min || (int) $v > $max) {
        error("parametro {$nome} invalido: use um inteiro de {$min} a {$max}", 422);
    }
    return (int) $v;
}

function p_data(array $q, string $nome): ?string
{
    $v = p_texto($q, $nome);
    if ($v === null) {
        return null;
    }
    $v = trim($v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        error("parametro {$nome} invalido: use AAAA-MM-DD", 422);
    }
    return $v;
}

/** Aceita CRITICA, critica, Critica... e tambem "CRITICA,ALTA". Fora das tres = 422. */
function p_faixas(array $q): array
{
    $saida = [];
    foreach ($q['faixa'] ?? [] as $bruto) {
        foreach (explode(',', $bruto) as $f) {
            $f = strtoupper(trim($f));
            if ($f === '') {
                continue;
            }
            if (!in_array($f, FAIXAS, true)) {
                error("faixa invalida: '{$f}' -- use CRITICA, ALTA ou MEDIA", 422);
            }
            if (!in_array($f, $saida, true)) {
                $saida[] = $f;
            }
        }
    }
    return $saida;
}

function data_iso($v): ?string
{
    if ($v === null) {
        return null;
    }
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d');
    }
    return substr((string) $v, 0, 10);
}

/**
 * DT_CORTE que o job ja deveria ter gravado agora: a vespera da execucao mais
 * recente do calendario (a de hoje so conta depois de EVASAO_HORA_JOB).
 */
function fila_esperada(): string
{
    $dias = [];
    foreach (explode(',', Banco::env('EVASAO_DIAS_JOB', DIAS_JOB_PADRAO)) as $d) {
        $d = trim($d);
        if (ctype_digit($d) && (int) $d >= 1 && (int) $d <= 7) {
            $dias[] = (int) $d;
        }
    }
    if (!$dias) {
        $dias = range(1, 7);
    }
    $hora = (int) Banco::env('EVASAO_HORA_JOB', (string) HORA_JOB_PADRAO);
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

function dias_entre(string $de, string $ate): int
{
    $utc = new DateTimeZone('UTC');
    return intdiv((new DateTimeImmutable($ate, $utc))->getTimestamp()
                - (new DateTimeImmutable($de, $utc))->getTimestamp(), 86400);
}

/** Sem data = fila mais recente. Com data = precisa existir na tabela. */
function resolver_data(?string $data): string
{
    if ($data === null) {
        $r = consultar('SELECT MAX(DT_CORTE) AS d FROM ' . tabela());
        if (!$r || $r[0]['d'] === null) {
            error('tabela vazia -- o job ainda nao gravou nenhuma fila', 404);
        }
        return data_iso($r[0]['d']);
    }
    $r = consultar('SELECT TOP 1 DT_CORTE AS d FROM ' . tabela() . ' WHERE DT_CORTE = ?', [$data]);
    if (!$r) {
        error("nao ha fila gravada para {$data} -- veja fila/datas", 404);
    }
    return $data;
}

/**
 * So digitos = codigo exato (ex. 258). Texto = trecho do nome, sem ligar para
 * acento nem maiuscula (o collation do banco e CI_AI): 'engenharia', 'mecanica'.
 */
function filtro_curso(?string $curso): array
{
    if ($curso === null || trim($curso) === '') {
        return ['', []];
    }
    $curso = trim($curso);
    if (ctype_digit($curso)) {
        // No hibrido o mesmo curso tem dois codigos no ERP academico (par 2xxx/8xxx = a
        // mesma autorizacao MEC, semipresencial de 2025); os alunos ficam divididos
        // entre os dois. Comparar pelos 3 ultimos digitos traz o conjunto completo
        // -- buscar 2111 ou 8111 devolve todos de Nutricao. So no hibrido: ali os
        // sufixos colidem de proposito; no presencial e no EAD cada sufixo e de um
        // unico curso (35/35 e 65/65, medido), entao mantemos CURSO = ? e nao
        // arriscamos super-selecao se um curso novo colidir no futuro.
        if (modalidade() === 'hibrido') {
            return [' AND RIGHT(f.CURSO, 3) = RIGHT(?, 3)', [$curso]];
        }
        return [' AND f.CURSO = ?', [$curso]];
    }
    // % _ [ digitados valem como texto, nao como curinga do LIKE
    $curso = strtr($curso, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']);
    return [" AND f.NOME_CURSO LIKE ? ESCAPE '!'", ['%' . $curso . '%']];
}

/** Faixa como numero para comparar: CRITICA > ALTA > MEDIA > fora da fila. */
function faixa_rank(?string $f): int
{
    switch ($f) {
        case 'CRITICA': return 3;
        case 'ALTA':    return 2;
        case 'MEDIA':   return 1;
        default:        return 0;   // nulo = fora da fila naquele dia
    }
}

/**
 * Para onde o aluno se moveu entre as DUAS filas mais recentes da tabela
 * (nao as duas em que ele aparece -- "fora da fila" conta como rank 0). Serve
 * para triagem: quem esta piorando primeiro.
 *   novo     entrou na fila agora (estava fora na anterior)
 *   subiu    faixa de maior risco que na anterior
 *   estavel  mesma faixa
 *   desceu   faixa de menor risco
 *   saiu     estava na fila anterior, saiu agora
 *   null     so existe uma fila, ou estava fora nas duas (nada a dizer)
 */
function tendencia_aluno(string $ra): ?string
{
    $r = consultar('
        SELECT r.CHANCE_EVASAO
        FROM (SELECT DISTINCT TOP 2 DT_CORTE FROM ' . tabela() . ' ORDER BY DT_CORTE DESC) d
        LEFT JOIN ' . tabela() . ' r ON r.DT_CORTE = d.DT_CORTE AND r.ALUNO = ?
        ORDER BY d.DT_CORTE DESC', [$ra]);
    if (count($r) < 2) {
        return null;   // uma fila so: nada para comparar
    }
    $ru = faixa_rank($r[0]['CHANCE_EVASAO'] ?? null);   // ultima
    $rp = faixa_rank($r[1]['CHANCE_EVASAO'] ?? null);   // penultima
    if ($ru === 0 && $rp === 0) {
        return null;
    }
    if ($rp === 0) {
        return 'novo';
    }
    if ($ru === 0) {
        return 'saiu';
    }
    if ($ru > $rp) {
        return 'subiu';
    }
    if ($ru < $rp) {
        return 'desceu';
    }
    return 'estavel';
}

/** A fila gravada imediatamente ANTES de $dia (ou null se $dia e a primeira). */
function fila_anterior(string $dia): ?string
{
    $r = consultar('SELECT DISTINCT TOP 1 DT_CORTE AS d FROM ' . tabela()
                 . ' WHERE DT_CORTE < ? ORDER BY DT_CORTE DESC', [$dia]);
    return $r ? data_iso($r[0]['d']) : null;
}

function aluno_json(array $r): array
{
    $j = [
        'dt_corte' => data_iso($r['DT_CORTE']),
        'posicao' => (int) $r['POSICAO'],
        'aluno' => $r['ALUNO'],
        'chance_evasao' => $r['CHANCE_EVASAO'],
        'curso' => $r['CURSO'],
        'nome_curso' => $r['NOME_CURSO'],
    ];
    // Quando a consulta traz os MOTIVO* (o /listar, via fila_cte), expoe tambem
    // 'fatores' -- SO os motivos gravados pelo job, sem o financeiro ao vivo que o
    // /consultar soma (seriam ~600 consultas numa fila inteira). Quem precisa do
    // financeiro por aluno usa o /consultar. O historico do /consultar nao
    // seleciona MOTIVO*, entao nao ganha o campo (fica igual ao de antes).
    if (array_key_exists('MOTIVO', $r)) {
        $fatores = [];
        foreach (['MOTIVO', 'MOTIVO2', 'MOTIVO3'] as $c) {
            $v = $r[$c] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $fatores[] = trim($v);
            }
        }
        $j['fatores'] = $fatores;
    }
    return $j;
}

function contagem_faixas(array $r): array
{
    return ['critica' => (int) $r['critica'], 'alta' => (int) $r['alta'],
            'media' => (int) $r['media'], 'total' => (int) $r['total']];
}
