<?php
/*
 * Regras compartilhadas pelos endpoints: leitura e validacao dos parametros,
 * datas da fila, filtro de curso e a consulta base da fila do dia.
 */

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/banco.php';

date_default_timezone_set(Banco::env('EVASAO_TZ', 'America/Sao_Paulo'));

const TABELA = 'BANCO_APP.dbo.TABELA_RISCO_EAD';
const CURSOS = 'BANCO_ERP.dbo.TABELA_CURSOS';
const ALUNOS = 'BANCO_ERP.dbo.TABELA_ALUNOS';
const FAIXAS = ['CRITICA', 'ALTA', 'MEDIA'];

// Calendario do job no integrador (EVASAO_DIAS_JOB / EVASAO_HORA_JOB, no ambiente
// ou no config.local.php): em quais dias ele roda (ISO: 1 = segunda ... 7 = domingo)
// e ate que hora ja terminou. Cada execucao grava DT_CORTE = vespera.
// Padrao: segunda e quinta, pronto as 8h (roda ~06:30).
const DIAS_JOB_PADRAO = '1,4';
const HORA_JOB_PADRAO = 8;

// A posicao sai do ID: o job insere a fila do dia em ordem, do maior risco para
// o menor. E calculada ANTES do filtro, entao e a posicao na fila geral.
const FILA = '
WITH fila AS (
    SELECT r.DT_CORTE, r.ALUNO, r.CHANCE_EVASAO, r.CURSO, c.NOME AS NOME_CURSO,
           ROW_NUMBER() OVER (ORDER BY r.ID) AS POSICAO
    FROM ' . TABELA . ' r
    LEFT JOIN ' . CURSOS . ' c ON c.CURSO = r.CURSO
    WHERE r.DT_CORTE = ?
)
';

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
        $r = consultar('SELECT MAX(DT_CORTE) AS d FROM ' . TABELA);
        if (!$r || $r[0]['d'] === null) {
            error('tabela vazia -- o job ainda nao gravou nenhuma fila', 404);
        }
        return data_iso($r[0]['d']);
    }
    $r = consultar('SELECT TOP 1 DT_CORTE AS d FROM ' . TABELA . ' WHERE DT_CORTE = ?', [$data]);
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
        return [' AND f.CURSO = ?', [$curso]];
    }
    // % _ [ digitados valem como texto, nao como curinga do LIKE
    $curso = strtr($curso, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']);
    return [" AND f.NOME_CURSO LIKE ? ESCAPE '!'", ['%' . $curso . '%']];
}

function aluno_json(array $r): array
{
    return [
        'dt_corte' => data_iso($r['DT_CORTE']),
        'posicao' => (int) $r['POSICAO'],
        'aluno' => $r['ALUNO'],
        'chance_evasao' => $r['CHANCE_EVASAO'],
        'curso' => $r['CURSO'],
        'nome_curso' => $r['NOME_CURSO'],
    ];
}

function contagem_faixas(array $r): array
{
    return ['critica' => (int) $r['critica'], 'alta' => (int) $r['alta'],
            'media' => (int) $r['media'], 'total' => (int) $r['total']];
}
