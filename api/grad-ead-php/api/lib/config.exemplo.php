<?php
/*
 * Use este modelo para definir o token (ou trocar outra configuracao)
 * num servidor: copie para config.local.php (nesta mesma
 * pasta) e preencha. O config.local.php:
 *   - fica fora do git (.gitignore da pasta da API) -- pode guardar o token real;
 *   - nao e acessivel pela web (lib/.htaccess bloqueia a pasta inteira).
 * Variavel de ambiente com o mesmo nome (SetEnv no Apache, Docker) vale mais.
 *
 * Dentro do app-web so o token e necessario: a conexao vem do conexao.php.
 */
return [
    // token desta API
    // 'EVASAO_API_TOKEN' => '',

    // calendario do job no integrador, para saber se a fila esta em dia.
    // dias ISO (1 = segunda ... 7 = domingo) e a hora em que ele ja terminou.
    // 'EVASAO_DIAS_JOB' => '1,4',
    // 'EVASAO_HORA_JOB' => '8',
];
