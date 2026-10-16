<?php
/*
 * Router SO PARA O TESTE (php teste_api.php). O php -S nao le .htaccess, entao
 * este arquivo emula o que o api/.htaccess faz no Apache:
 *   - presencial/... e ead/... -> o arquivo real, com ?modalidade= ANTES da query
 *     do usuario (igual ao [QSA] do rewrite);
 *   - rota inexistente sob /api -> nao_encontrado.php (404 JSON).
 * Em producao quem roteia e o Apache; aqui e so para o teste exercitar a URL
 * com prefixo de modalidade (/api/presencial/fila/saude).
 */

declare(strict_types=1);

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');

// Modalidade no 1o segmento: lista fechada de endpoints, como no .htaccess.
if (preg_match('#^/api/(presencial|ead|hibrido)/(alunos/(?:listar|consultar)|fila/(?:resumo|datas|saude))$#', $uri, $m)) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    // modalidade= entra PRIMEIRO -- o PHP le a 1a ocorrencia e a rota manda na tabela
    $_SERVER['QUERY_STRING'] = 'modalidade=' . $m[1] . ($qs !== '' ? '&' . $qs : '');
    parse_str($_SERVER['QUERY_STRING'], $_GET);
    $_SERVER['SCRIPT_NAME'] = '/api/' . $m[2] . '.php';
    require __DIR__ . '/api/' . $m[2] . '.php';
    return true;
}

// Arquivo que existe (inclui /api/alunos/listar.php direto, docs, openapi): php -S serve.
if (is_file(__DIR__ . $uri)) {
    return false;
}

// Rota inexistente sob /api: 404 em JSON, como o .htaccess manda.
if (strpos($uri, '/api/') === 0) {
    require __DIR__ . '/api/nao_encontrado.php';
    return true;
}

return false;
