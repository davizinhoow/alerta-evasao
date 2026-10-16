<?php
/*
 * Autenticacao por token fixo: EVASAO_API_TOKEN, no cabecalho
 * "Authorization: Bearer <token>".
 *
 * Por que nao o requireAuth() (JWT) do agendamento-new: la quem chama e um
 * funcionario ou docente logado na intranet, com cookie. Aqui quem chama e o
 * assistente com IA, de servidor para servidor -- nao ha usuario logado.
 *
 * O token padrao e fixo e versionado (lib/config.php), para qualquer um testar.
 * Sem token nenhum a API RECUSA tudo (503) -- em 25/09/2026 um deploy sem token
 * deixou a lista de RAs aberta em app.instituicao.br. Para abrir de proposito
 * (teste local), defina EVASAO_API_ABERTA=1.
 */

declare(strict_types=1);

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/lib/banco.php';

function requireToken(): void
{
    if (Banco::env('EVASAO_API_ABERTA') === '1') {
        return;   // aberta de proposito: so teste local
    }
    $token = Banco::env('EVASAO_API_TOKEN');   // padrao: o token fixo de lib/config.php
    if ($token === '') {
        error_log('api evasao: sem EVASAO_API_TOKEN -- api/lib/config.php sumiu ou esta vazio');
        error('API sem token configurado -- confira api/lib/config.php (token fixo) ou config.local.php', 503);
    }
    // Apache/CGI costumam esconder o Authorization -- o .htaccess repassa
    $cab = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($cab === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strcasecmp((string) $nome, 'Authorization') === 0) {
                $cab = (string) $valor;
            }
        }
    }
    $recebido = preg_match('/^\s*Bearer\s+(\S+)\s*$/i', (string) $cab, $m) ? $m[1] : '';
    if ($recebido === '' || !hash_equals($token, $recebido)) {
        header('WWW-Authenticate: Bearer');
        error('token ausente ou invalido', 401);
    }
}
