<?php
/*
 * GET openapi -- a especificacao da API em JSON. Aberta (sem token): so
 * descreve as rotas, nao mostra aluno. E o que o assistente le para saber
 * como chamar cada endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/cors.php';

setCors();
apenasGet();

$spec = require __DIR__ . '/lib/openapi.php';
// base publica da API (ex. /api_evasao_grad_ead/api), para as rotas funcionarem em qualquer pasta
$base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$spec['servers'] = [['url' => $base === '' ? '/' : $base]];
json($spec);
