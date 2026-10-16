<?php
/*
 * Configuracao padrao da API.
 *
 * O token NAO fica no repositorio. Sem token definido a API recusa todas as
 * chamadas (503). Defina o seu de uma destas formas (a primeira vence):
 *   1. variavel de ambiente EVASAO_API_TOKEN (SetEnv no Apache, Docker...);
 *   2. api/lib/config.local.php (fora do git -- veja config.exemplo.php);
 *   3. este arquivo (nao recomendado: ele e versionado).
 */
return [
    'EVASAO_API_TOKEN' => '',
];
