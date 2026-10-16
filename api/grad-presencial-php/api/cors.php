<?php
/*
 * Cabecalhos e respostas JSON -- mesmo formato do agendamento-new/api/cors.php
 * (setCors, json, error), para quem mantem o app-web reconhecer o desenho.
 *
 * Diferencas de proposito: so GET (a API so le), e excecao nao tratada responde
 * "erro interno" -- a mensagem vai para o log, nao para quem chamou (pode trazer
 * nome de servidor ou trecho de SQL).
 */

declare(strict_types=1);

function setCors(): void
{
    $permitidas = [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost',
        'https://app.instituicao.br',
        'https://intranet.instituicao.br',
    ];

    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origem, $permitidas, true)) {
        header("Access-Control-Allow-Origin: $origem");
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    // aviso do PHP no meio da resposta quebraria o JSON: vira excecao e cai no 500
    ini_set('display_errors', '0');
    set_error_handler(function (int $nivel, string $msg, string $arq = '', int $linha = 0): bool {
        if (!(error_reporting() & $nivel)) {
            return false;
        }
        throw new ErrorException($msg, 0, $nivel, $arq, $linha);
    });
    set_exception_handler(function (Throwable $e): void {
        error_log('api evasao: ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'erro interno'], JSON_UNESCAPED_UNICODE);
        exit;
    });
}

/** So leitura: GET (e HEAD, que o Apache responde sem corpo). */
function apenasGet(): void
{
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo !== 'GET' && $metodo !== 'HEAD') {
        header('Allow: GET');
        error('metodo nao permitido -- esta API so le (GET)', 405);
    }
}

function json($dados, int $status = 200): void
{
    $corpo = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($corpo === false) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode(['error' => 'falha ao montar resposta (' . json_last_error_msg() . ')'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!headers_sent()) {
        http_response_code($status);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $corpo;
    }
    exit;
}

function error(string $msg, int $status = 400): void
{
    json(['error' => $msg], $status);
}
