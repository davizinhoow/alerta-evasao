<?php
/*
 * GET {modalidade}/fila/resumo -- quantos alunos por curso e faixa.
 * {modalidade} = presencial | ead.
 *
 *   curso   codigo ou trecho do nome
 *   data    AAAA-MM-DD (padrao: a fila mais recente)
 */

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/fila.php';

setCors();
apenasGet();
requireToken();

$q = parametros();
$dia = resolver_data(p_data($q, 'data'));
[$onde, $params] = filtro_curso(p_texto($q, 'curso'));

$linhas = consultar(fila_cte() . '
    SELECT f.CURSO, f.NOME_CURSO,' . str_replace('CHANCE_EVASAO', 'f.CHANCE_EVASAO', SOMA_FAIXAS) . "
    FROM fila f
    WHERE 1 = 1 {$onde}
    GROUP BY f.CURSO, f.NOME_CURSO
    ORDER BY critica DESC, total DESC
", array_merge([$dia], $params));

json(array_map(function (array $r): array {
    return array_merge(['curso' => $r['CURSO'], 'nome_curso' => $r['NOME_CURSO']], contagem_faixas($r));
}, $linhas));
