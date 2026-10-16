<?php
/*
 * GET {modalidade}/fila/datas -- filas gravadas, da mais recente para a mais
 * antiga, com o total de cada faixa. {modalidade} = presencial | ead.
 *
 *   limite  1 a 366 (padrao 60)
 */

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/fila.php';

setCors();
apenasGet();
requireToken();

$limite = p_inteiro(parametros(), 'limite', 60, 1, 366);

$linhas = consultar("SELECT TOP ({$limite}) DT_CORTE," . SOMA_FAIXAS . '
    FROM ' . tabela() . '
    GROUP BY DT_CORTE
    ORDER BY DT_CORTE DESC');

json(array_map(function (array $r): array {
    return array_merge(['dt_corte' => data_iso($r['DT_CORTE'])], contagem_faixas($r));
}, $linhas));
