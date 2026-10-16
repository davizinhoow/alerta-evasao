<?php
/*
 * Resposta para rota inexistente (o .htaccess manda para ca). 404 em JSON, com
 * as rotas validas -- em vez da pagina HTML do Apache. Aberta: so lista nomes de
 * rotas, que ja sao publicos no openapi.
 */

declare(strict_types=1);

require_once __DIR__ . '/cors.php';

setCors();

$pedido = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$dica = preg_match('#/alunos/([^/]+)/?$#', $pedido, $m) && !in_array($m[1], ['listar', 'consultar'], true)
    ? " -- para um aluno, use alunos/consultar?ra={$m[1]}"
    : '';

error('rota nao encontrada' . $dica . ' -- rotas: alunos/listar, alunos/consultar?ra=, '
      . 'fila/resumo, fila/datas, fila/saude, docs, openapi', 404);
