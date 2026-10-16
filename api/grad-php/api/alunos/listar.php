<?php
/*
 * GET {modalidade}/alunos/listar -- alunos em risco na fila do dia, com filtro.
 * {modalidade} = presencial | ead | hibrido (1o segmento da URL).
 *
 *   curso   codigo (258) ou trecho do nome (engenharia); acento e maiuscula nao importam
 *   faixa   CRITICA, ALTA, MEDIA -- repetida (faixa=CRITICA&faixa=ALTA) ou CRITICA,ALTA
 *   novos   1 = so quem entrou na fila agora (nao estava na fila anterior)
 *   data    AAAA-MM-DD (padrao: a fila mais recente)
 *   pagina  a partir de 1        limite  1 a 1000 (padrao 100)
 *
 * Cada aluno traz 'fatores': os motivos do job (0 a 3). Aqui vem so os da tabela,
 * sem o financeiro ao vivo do /consultar -- quem quer o financeiro por aluno usa la.
 *
 * Ex.: presencial/alunos/listar?curso=engenharia&faixa=CRITICA
 */

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/fila.php';

setCors();
apenasGet();
requireToken();

$q = parametros();
$faixas = p_faixas($q);
$pagina = p_inteiro($q, 'pagina', 1, 1, 1000000);
$limite = p_inteiro($q, 'limite', 100, 1, 1000);
$dia = resolver_data(p_data($q, 'data'));

[$onde, $params] = filtro_curso(p_texto($q, 'curso'));
if ($faixas) {
    $onde .= ' AND f.CHANCE_EVASAO IN (' . implode(',', array_fill(0, count($faixas), '?')) . ')';
    $params = array_merge($params, $faixas);
}
// novos=1: so quem NAO estava na fila imediatamente anterior (entrou agora). Sem
// fila anterior (primeira fila da tabela) o filtro nao se aplica -- todos "novos".
$novos = p_texto($q, 'novos');
if ($novos !== null && in_array(strtolower($novos), ['1', 'true', 'sim'], true)) {
    $anterior = fila_anterior($dia);
    if ($anterior !== null) {
        $onde .= ' AND f.ALUNO NOT IN (SELECT ALUNO FROM ' . tabela() . ' WHERE DT_CORTE = ?)';
        $params[] = $anterior;
    }
}

// inteiros ja validados: entram literais (TOP/OFFSET com parametro texto falham no ODBC)
$linhas = consultar(fila_cte() . "
    SELECT f.*, COUNT(*) OVER () AS TOTAL
    FROM fila f
    WHERE 1 = 1 {$onde}
    ORDER BY f.POSICAO
    OFFSET " . (($pagina - 1) * $limite) . " ROWS FETCH NEXT {$limite} ROWS ONLY
", array_merge([$dia], $params));

$total = $linhas ? (int) $linhas[0]['TOTAL'] : 0;
if (!$linhas && $pagina > 1) {
    // pagina alem do fim: devolve vazio, mas com o total certo
    $total = (int) consultar(fila_cte() . "SELECT COUNT(*) AS n FROM fila f WHERE 1 = 1 {$onde}",
                             array_merge([$dia], $params))[0]['n'];
}

json([
    'dt_corte' => $dia,
    'fila_atualizada' => $dia >= fila_esperada(),   // false = nao e a fila mais recente esperada
    'total' => $total,                              // quantos atendem o filtro (sem paginacao)
    'pagina' => $pagina,
    'limite' => $limite,
    'alunos' => array_map('aluno_json', $linhas),
]);
