<?php
/*
 * GET alunos/consultar?ra=9000001 -- situacao de um aluno na fila mais
 * recente e nas anteriores.
 *
 *   ra     RA do aluno (obrigatorio)
 *   dias   quantas filas mais recentes olhar, 1 a 366 (padrao 30)
 *
 * Tres respostas, sem ambiguidade:
 *   RA nao existe no ERP academico         -> 404
 *   existe, fora das faixas         -> faixa_ultima_fila: null
 *   esta numa faixa                 -> faixa_ultima_fila: "CRITICA" | "ALTA" | "MEDIA"
 */

declare(strict_types=1);

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/fila.php';

setCors();
apenasGet();
requireToken();

$q = parametros();
$dias = p_inteiro($q, 'dias', 30, 1, 366);
$ra = trim((string) p_texto($q, 'ra'));
if ($ra === '') {
    error('informe o RA: alunos/consultar?ra=...', 422);
}

// O ERP academico grava o RA com 7 ou 8 digitos e zero a esquerda (0527810). Quem copia
// de planilha costuma perder o zero (527810) ou trazer ponto/traco: tenta tambem
// essas formas. Preferencia: exatamente como veio, depois so digitos, 7 e 8 casas.
$candidatos = [$ra];
$digitos = preg_replace('/[\s.\-]/', '', $ra);
if ($digitos !== '' && ctype_digit($digitos)) {
    foreach ([$digitos, str_pad($digitos, 7, '0', STR_PAD_LEFT), str_pad($digitos, 8, '0', STR_PAD_LEFT)] as $c) {
        if (!in_array($c, $candidatos, true)) {
            $candidatos[] = $c;
        }
    }
}
$cad = consultar('
    SELECT a.ALUNO, a.COL_SITUACAO, a.CURSO, c.NOME AS NOME_CURSO
    FROM ' . ALUNOS . ' a LEFT JOIN ' . CURSOS . ' c ON c.CURSO = a.CURSO
    WHERE a.ALUNO IN (' . implode(',', array_fill(0, count($candidatos), '?')) . ')', $candidatos);
if (!$cad) {
    error("RA {$ra} nao existe no ERP academico", 404);
}
usort($cad, function (array $x, array $y) use ($candidatos): int {
    return array_search($x['ALUNO'], $candidatos, true) <=> array_search($y['ALUNO'], $candidatos, true);
});
$cad = $cad[0];
$ra = $cad['ALUNO'];   // o RA como o ERP academico grava -- e o que esta na fila
$ultima = resolver_data(null);

$hist = array_map('aluno_json', consultar('
    WITH fila AS (
        SELECT r.DT_CORTE, r.ALUNO, r.CHANCE_EVASAO, r.CURSO, c.NOME AS NOME_CURSO,
               ROW_NUMBER() OVER (PARTITION BY r.DT_CORTE ORDER BY r.ID) AS POSICAO
        FROM ' . TABELA . ' r
        LEFT JOIN ' . CURSOS . ' c ON c.CURSO = r.CURSO
        WHERE r.DT_CORTE IN (SELECT DISTINCT TOP (' . $dias . ') DT_CORTE FROM ' . TABELA . ' ORDER BY DT_CORTE DESC)
    )
    SELECT * FROM fila WHERE ALUNO = ? ORDER BY DT_CORTE DESC', [$ra]));

$atual = ($hist && $hist[0]['dt_corte'] === $ultima) ? $hist[0] : null;
if ($atual) {
    $leitura = "{$atual['chance_evasao']} na fila de {$ultima}, posicao {$atual['posicao']}.";
} else {
    // o job so grava as tres faixas: fora da tabela = os ~80% de menor risco,
    // OU aluno que o job nem pontua (fora da graduacao EAD)
    $leitura = "Fora das faixas de risco na fila de {$ultima}: ou esta entre os ~80% "
             . 'de menor risco, ou nao e aluno da graduacao EAD pontuado pelo job.';
}
if ($cad['COL_SITUACAO'] !== null && $cad['COL_SITUACAO'] !== '' && $cad['COL_SITUACAO'] !== 'Ativo') {
    $leitura .= " Situacao atual no ERP academico: {$cad['COL_SITUACAO']}.";
}
$atualizada = $ultima >= fila_esperada();
if (!$atualizada) {
    $leitura .= ' ATENCAO: fila desatualizada -- o job nao gravou a mais recente (esperada: ' . fila_esperada() . ').';
}

json([
    'aluno' => $cad['ALUNO'],
    'sit_aluno' => $cad['COL_SITUACAO'],      // situacao ATUAL -- pode ter mudado depois da fila
    'curso' => $cad['CURSO'],
    'nome_curso' => $cad['NOME_CURSO'],
    'ultima_fila' => $ultima,
    'fila_atualizada' => $atualizada,
    'faixa_ultima_fila' => $atual ? $atual['chance_evasao'] : null,
    'leitura' => $leitura,                  // o resultado em uma frase
    'historico' => $hist,                   // filas recentes em que apareceu
]);
