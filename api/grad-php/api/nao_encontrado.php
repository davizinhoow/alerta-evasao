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

// dica 1: faltou a modalidade no comeco (alunos/listar em vez de presencial/alunos/listar)
if (preg_match('#/(alunos/(?:listar|consultar)|fila/(?:resumo|datas|saude))/?$#', $pedido, $m)) {
    error("rota nao encontrada -- falta a modalidade no comeco: use presencial/{$m[1]}, ead/{$m[1]} ou hibrido/{$m[1]}", 404);
}
// dica 2: colocaram o RA no caminho (formato antigo da versao Python)
$dica = preg_match('#/(presencial|ead|hibrido)/alunos/([^/]+)/?$#', $pedido, $m) && !in_array($m[2], ['listar', 'consultar'], true)
    ? " -- para um aluno, use {$m[1]}/alunos/consultar?ra={$m[2]}"
    : '';

error('rota nao encontrada' . $dica . ' -- rotas: {modalidade}/alunos/listar, '
      . '{modalidade}/alunos/consultar?ra=, {modalidade}/fila/resumo, {modalidade}/fila/datas, '
      . '{modalidade}/fila/saude (modalidade = presencial | ead | hibrido); docs, openapi', 404);
