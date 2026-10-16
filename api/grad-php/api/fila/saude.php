<?php
/*
 * GET {modalidade}/fila/saude -- o banco responde e se a fila mais recente e a
 * esperada pelo calendario do job. {modalidade} = presencial | ead.
 *
 * A API nunca falha se o job parar: ela so passa a servir fila velha. Este e o
 * lugar de conferir -- fila_atualizada: false = o job nao gravou a fila da
 * ultima execucao do calendario (segunda e quinta, por padrao).
 */

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/fila.php';

setCors();
apenasGet();
requireToken();

$r = consultar('SELECT MAX(DT_CORTE) AS ultima, COUNT(DISTINCT DT_CORTE) AS dias FROM ' . tabela())[0];
$ultima = data_iso($r['ultima']);
$esperada = fila_esperada();
$atraso = $ultima === null ? null : dias_entre($ultima, $esperada);

json([
    'status' => 'ok',
    'ultima_fila' => $ultima,
    'fila_esperada' => $esperada,
    'fila_atualizada' => $atraso !== null && $atraso <= 0,
    'dias_de_atraso' => $atraso === null ? null : max($atraso, 0),
    'dias_gravados' => (int) $r['dias'],
]);
