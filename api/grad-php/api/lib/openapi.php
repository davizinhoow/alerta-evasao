<?php
/*
 * Especificacao OpenAPI 3.1 servida em api/openapi -- e o que o assistente (ou
 * o Swagger em api/docs) le para saber como chamar cada rota. Se mudar um
 * endpoint (api/alunos/*.php, api/fila/*.php), mude aqui tambem.
 */

declare(strict_types=1);

$faixa = ['type' => 'string', 'enum' => ['CRITICA', 'ALTA', 'MEDIA']];
$dataFila = ['name' => 'data', 'in' => 'query', 'required' => false,
             'description' => 'DT_CORTE no formato AAAA-MM-DD (padrão: a fila mais recente)',
             'schema' => ['type' => 'string', 'format' => 'date']];
$curso = ['name' => 'curso', 'in' => 'query', 'required' => false,
          'description' => 'código (258) ou trecho do nome (engenharia, direito); acento e maiúscula não importam',
          'schema' => ['type' => 'string']];
// modalidade e o 1o segmento da URL: define a tabela (presencial, EAD ou hibrido)
$modalidade = ['name' => 'modalidade', 'in' => 'path', 'required' => true,
               'description' => 'presencial, ead ou hibrido -- escolhe a fila/tabela da modalidade '
                   . '(hibrido ja roteia, mas a fila so aparece quando o job preencher a tabela)',
               'schema' => ['type' => 'string', 'enum' => ['presencial', 'ead', 'hibrido']]];
$ref = function (string $nome): array {
    return ['$ref' => '#/components/schemas/' . $nome];
};
$ok = function (array $schema, string $descricao = 'OK'): array {
    return ['description' => $descricao, 'content' => ['application/json' => ['schema' => $schema]]];
};
$erro = function (string $descricao) use ($ref): array {
    return ['description' => $descricao, 'content' => ['application/json' => ['schema' => $ref('Erro')]]];
};
$errosComuns = [
    '401' => $erro('token ausente ou inválido (só quando EVASAO_API_TOKEN está definida)'),
    '405' => $erro('método diferente de GET'),
    '422' => $erro('parâmetro inválido'),
    '503' => $erro('banco indisponível'),
];
$contagem = [
    'critica' => ['type' => 'integer'],
    'alta' => ['type' => 'integer'],
    'media' => ['type' => 'integer'],
    'total' => ['type' => 'integer'],
];
$textoOuNulo = ['type' => ['string', 'null']];

return [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'Risco de evasão -- Graduação (Presencial + EAD + Híbrido)',
        'version' => '1.0',
        'description' => 'Uma API, as modalidades da graduação. A modalidade é o 1º segmento da URL: '
            . '/presencial/... lê a fila de TABELA_RISCO_PRESENCIAL; /ead/... lê TABELA_RISCO_EAD; '
            . '/hibrido/... lê TABELA_RISCO_HIBRIDO (roteamento pronto; fila publicada quando houver job). '
            . 'Faixas pela posição na fila do dia: CRITICA = 20% do topo da ALTA (~2% da base), '
            . 'ALTA = até 10%, MEDIA = até 20%. Aluno fora da tabela = sem risco. '
            . 'Confira sempre fila_atualizada: false significa que a fila não é a mais recente esperada '
            . '(o job roda segunda e quinta). '
            . 'A faixa é uma estimativa (cerca de 4 em cada 10 da CRITICA evadem) e nunca deve ser '
            . 'comunicada ao próprio aluno.',
    ],
    'security' => [['bearer' => []]],
    'paths' => [
        '/{modalidade}/fila/saude' => ['get' => [
            'operationId' => 'saude',
            'summary' => 'O banco responde e se a fila mais recente é a de hoje',
            'parameters' => [$modalidade],
            'responses' => ['200' => $ok($ref('Saude'))] + $errosComuns,
        ]],
        '/{modalidade}/alunos/listar' => ['get' => [
            'operationId' => 'listar_alunos',
            'summary' => 'Alunos em risco, com filtro por curso e faixa',
            'parameters' => [
                $modalidade,
                $curso,
                ['name' => 'faixa', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true,
                 'description' => 'uma ou mais: CRITICA, ALTA, MEDIA (repita o parâmetro ou separe por vírgula)',
                 'schema' => ['type' => 'array', 'items' => $faixa]],
                ['name' => 'novos', 'in' => 'query', 'required' => false,
                 'description' => '1 = só quem entrou na fila agora (não estava na fila imediatamente anterior)',
                 'schema' => ['type' => 'string', 'enum' => ['1']]],
                $dataFila,
                ['name' => 'pagina', 'in' => 'query', 'required' => false,
                 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                ['name' => 'limite', 'in' => 'query', 'required' => false,
                 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 100]],
            ],
            'responses' => ['200' => $ok($ref('PaginaAlunos')),
                            '404' => $erro('não há fila gravada para a data')] + $errosComuns,
        ]],
        '/{modalidade}/alunos/consultar' => ['get' => [
            'operationId' => 'consultar_aluno',
            'summary' => 'Situação de um aluno na fila mais recente e nas anteriores',
            'parameters' => [
                $modalidade,
                ['name' => 'ra', 'in' => 'query', 'required' => true, 'description' => 'RA do aluno',
                 'schema' => ['type' => 'string']],
                ['name' => 'dias', 'in' => 'query', 'required' => false,
                 'description' => 'quantas filas mais recentes olhar',
                 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 366, 'default' => 30]],
            ],
            'responses' => ['200' => $ok($ref('HistoricoAluno')),
                            '404' => $erro('RA não existe no ERP academico')] + $errosComuns,
        ]],
        '/{modalidade}/fila/resumo' => ['get' => [
            'operationId' => 'resumo_por_curso',
            'summary' => 'Contagem por curso e faixa',
            'parameters' => [$modalidade, $curso, $dataFila],
            'responses' => ['200' => $ok(['type' => 'array', 'items' => $ref('ResumoCurso')]),
                            '404' => $erro('não há fila gravada para a data')] + $errosComuns,
        ]],
        '/{modalidade}/fila/datas' => ['get' => [
            'operationId' => 'listar_datas',
            'summary' => 'Filas gravadas, da mais recente para a mais antiga',
            'parameters' => [
                $modalidade,
                ['name' => 'limite', 'in' => 'query', 'required' => false,
                 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 366, 'default' => 60]],
            ],
            'responses' => ['200' => $ok(['type' => 'array', 'items' => $ref('DataCorte')])] + $errosComuns,
        ]],
    ],
    'components' => [
        'securitySchemes' => [
            'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'valor de EVASAO_API_TOKEN'],
        ],
        'schemas' => [
            'Aluno' => ['type' => 'object', 'properties' => [
                'dt_corte' => ['type' => 'string', 'format' => 'date'],
                'posicao' => ['type' => 'integer',
                              'description' => 'posição na fila do dia (1 = maior risco), contando TODOS os cursos'],
                'aluno' => ['type' => 'string', 'description' => 'RA'],
                'chance_evasao' => $faixa,
                'curso' => $textoOuNulo,
                'nome_curso' => $textoOuNulo,
                'fatores' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' =>
                              'motivos gravados pelo job (0 a 3), descritivo e uso interno. So os da tabela -- '
                              . 'sem o financeiro ao vivo do /consultar'],
            ], 'required' => ['dt_corte', 'posicao', 'aluno', 'chance_evasao', 'curso', 'nome_curso', 'fatores']],
            'PaginaAlunos' => ['type' => 'object', 'properties' => [
                'dt_corte' => ['type' => 'string', 'format' => 'date'],
                'fila_atualizada' => ['type' => 'boolean',
                                      'description' => 'false = não é a fila mais recente esperada (job atrasado ou data antiga pedida)'],
                'total' => ['type' => 'integer', 'description' => 'quantos atendem o filtro (sem paginação)'],
                'pagina' => ['type' => 'integer'],
                'limite' => ['type' => 'integer'],
                'alunos' => ['type' => 'array', 'items' => $ref('Aluno')],
            ], 'required' => ['dt_corte', 'fila_atualizada', 'total', 'pagina', 'limite', 'alunos']],
            'HistoricoAluno' => ['type' => 'object', 'properties' => [
                'aluno' => ['type' => 'string'],
                'sit_aluno' => ['type' => ['string', 'null'],
                                'description' => 'situação ATUAL no ERP academico (Ativo, Cancelado...) -- pode ter mudado depois da fila'],
                'curso' => $textoOuNulo,
                'nome_curso' => $textoOuNulo,
                'ultima_fila' => ['type' => 'string', 'format' => 'date'],
                'fila_atualizada' => ['type' => 'boolean'],
                'faixa_ultima_fila' => ['type' => ['string', 'null'], 'enum' => ['CRITICA', 'ALTA', 'MEDIA', null],
                                        'description' => 'null = fora das faixas na fila mais recente'],
                'tendencia' => ['type' => ['string', 'null'], 'enum' => ['novo', 'subiu', 'estavel', 'desceu', 'saiu', null],
                                'description' => 'movimento entre as 2 filas mais recentes: novo (entrou agora), '
                                    . 'subiu (faixa de maior risco), estavel, desceu, saiu (deixou a fila), '
                                    . 'null (so ha uma fila ou esteve fora nas duas). Para triagem: subiu/novo primeiro'],
                'leitura' => ['type' => 'string', 'description' => 'o resultado em uma frase (faixa, posicao, situacao). Os fatores vao SO no campo fatores'],
                'fatores' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' =>
                              'onde o aluno tem mais dificuldade, do mais forte ao mais fraco (frases prontas). '
                              . 'No presencial, para quem esta na fila, vem dos motivos gravados pelo job '
                              . '(modelo: AVA + academico) mais o financeiro ao vivo; no EAD e para quem esta '
                              . 'fora da fila, tudo ao vivo (AVA + financeiro). DESCRITIVO, nao e a atribuicao '
                              . 'exata do modelo. Uso interno da equipe -- nunca comunicar ao aluno (LGPD). '
                              . 'Vazio = sem fator isolado claro.'],
                'historico' => ['type' => 'array', 'items' => $ref('Aluno'),
                                'description' => 'filas recentes em que apareceu, da mais recente para a mais antiga'],
            ], 'required' => ['aluno', 'sit_aluno', 'curso', 'nome_curso', 'ultima_fila', 'fila_atualizada',
                              'faixa_ultima_fila', 'tendencia', 'leitura', 'fatores', 'historico']],
            'ResumoCurso' => ['type' => 'object',
                              'properties' => ['curso' => $textoOuNulo, 'nome_curso' => $textoOuNulo] + $contagem,
                              'required' => ['curso', 'nome_curso', 'critica', 'alta', 'media', 'total']],
            'DataCorte' => ['type' => 'object',
                            'properties' => ['dt_corte' => ['type' => 'string', 'format' => 'date']] + $contagem,
                            'required' => ['dt_corte', 'critica', 'alta', 'media', 'total']],
            'Saude' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string'],
                'ultima_fila' => ['type' => ['string', 'null'], 'format' => 'date'],
                'fila_esperada' => ['type' => 'string', 'format' => 'date'],
                'fila_atualizada' => ['type' => 'boolean'],
                'dias_de_atraso' => ['type' => ['integer', 'null']],
                'dias_gravados' => ['type' => 'integer'],
            ]],
            'Erro' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string']]],
        ],
    ],
];
