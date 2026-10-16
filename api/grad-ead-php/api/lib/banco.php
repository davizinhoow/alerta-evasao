<?php
/*
 * Conexao com o SQL Server -- somente leitura.
 *
 * Dois jeitos de conectar, nesta ordem:
 *   1. EVASAO_SQL_SERVER definida -> usa as variaveis abaixo (fora do app-web,
 *      testes locais).
 *   2. Sem ela -> procura o conexao.php do app-web subindo pelas pastas (esta
 *      lib fica em app-web/<pasta>/api/lib/, o conexao.php em app-web/) e usa
 *      Conexao::getConnection('instituicao'), o padrao dos outros apps. Nesse caso
 *      a API so precisa de EVASAO_API_TOKEN.
 *
 * Funciona com os dois drivers PDO que falam com SQL Server:
 *   pdo_sqlsrv  (Microsoft, o comum em producao)  -- textos ja chegam em UTF-8
 *   pdo_odbc    (vem no PHP do Windows/XAMPP)      -- textos chegam no codepage do
 *               cliente (Windows-1252 no Windows) e sao convertidos aqui
 *
 * O banco e latin-1 (Latin1_General_CI_AI). Sem a conversao, "QUIMICA" com
 * acento vira "QU?MICA" ou quebra o json_encode.
 *
 * Variaveis de ambiente:
 *   EVASAO_SQL_SERVER      servidor (obrigatoria)
 *   EVASAO_SQL_USER / EVASAO_SQL_PASSWORD   login SQL; sem eles, login Windows
 *   EVASAO_PDO_DRIVER      auto (padrao) | sqlsrv | odbc
 *   EVASAO_SQL_DRIVER      so pdo_odbc: padrao "ODBC Driver 17 for SQL Server"
 *   EVASAO_SQL_TRUST_CERT  1 (padrao) aceita o certificado do servidor -- o
 *                          ODBC Driver 18 exige criptografia e o servidor
 *                          interno nao tem certificado confiavel
 *   EVASAO_ODBC_CHARSET    so pdo_odbc: padrao Windows-1252 no Windows, UTF-8 fora
 */

declare(strict_types=1);

final class ErroBanco extends RuntimeException
{
}

final class Banco
{
    /** @var Banco|null */
    private static $instancia = null;

    /** @var PDO */
    private $pdo;

    /** @var string charset dos textos trocados com o driver */
    private $charset;

    public static function get(): Banco
    {
        if (self::$instancia === null) {
            self::$instancia = new Banco();
        }
        return self::$instancia;
    }

    /** @var array<string, string>|null  conteudo de config.local.php */
    private static $config = null;

    /**
     * Configuracao, nesta ordem: variavel de ambiente (SetEnv, Docker) >
     * api/lib/config.local.php (fora do git, por servidor) > api/lib/config.php
     * (padrao versionado, com o token fixo). Os dois arquivos ficam bloqueados na
     * web pelo lib/.htaccess.
     */
    public static function env(string $nome, string $padrao = ''): string
    {
        $v = self::bruto($nome);
        return trim($v) === '' ? $padrao : trim($v);
    }

    /** Valor sem trim (senha pode ter espaco). */
    private static function bruto(string $nome): string
    {
        $v = getenv($nome);
        if ($v !== false) {
            return $v;   // definida no ambiente (mesmo vazia): vale mais que os arquivos
        }
        if (self::$config === null) {
            // config.php = padrao versionado (token fixo); config.local.php = troca
            // por servidor, fora do git. O local sobrescreve o padrao.
            self::$config = [];
            foreach (['config.php', 'config.local.php'] as $nomeArquivo) {
                $arquivo = __DIR__ . '/' . $nomeArquivo;
                $c = is_file($arquivo) ? require $arquivo : [];
                if (is_array($c)) {
                    self::$config = array_merge(self::$config, $c);
                }
            }
        }
        $v = self::$config[$nome] ?? '';
        return is_string($v) ? $v : '';
    }

    private function __construct()
    {
        $servidor = self::env('EVASAO_SQL_SERVER');
        if ($servidor === '') {
            $arquivo = self::acharConexaoPhp();
            if ($arquivo === null) {
                throw new ErroBanco('defina EVASAO_SQL_SERVER, ou coloque a pasta da API dentro do app-web (abaixo do conexao.php)');
            }
            $this->conectarPeloApp($arquivo);
            return;
        }
        $usuario = self::env('EVASAO_SQL_USER');
        $senha = self::bruto('EVASAO_SQL_PASSWORD');   // senha nao leva trim
        if (($usuario === '') !== ($senha === '')) {
            throw new ErroBanco('defina EVASAO_SQL_USER e EVASAO_SQL_PASSWORD juntos, ou nenhum dos dois');
        }
        $confiaCert = self::env('EVASAO_SQL_TRUST_CERT', '1') === '1';

        $driver = strtolower(self::env('EVASAO_PDO_DRIVER', 'auto'));
        if ($driver === 'auto') {
            $driver = extension_loaded('pdo_sqlsrv') ? 'sqlsrv' : 'odbc';
        }

        if ($driver === 'sqlsrv') {
            if (!extension_loaded('pdo_sqlsrv')) {
                throw new ErroBanco('extensao pdo_sqlsrv nao carregada');
            }
            $dsn = 'sqlsrv:Server=' . $servidor . ';Database=BANCO_APP;LoginTimeout=15'
                 . ($confiaCert ? ';TrustServerCertificate=1' : '');
            $this->charset = 'UTF-8';   // padrao do pdo_sqlsrv (PDO::SQLSRV_ENCODING_UTF8)
        } elseif ($driver === 'odbc') {
            if (!extension_loaded('pdo_odbc')) {
                throw new ErroBanco('nenhum driver: instale pdo_sqlsrv ou habilite pdo_odbc');
            }
            $dsn = 'odbc:Driver={' . self::env('EVASAO_SQL_DRIVER', 'ODBC Driver 17 for SQL Server') . '}'
                 . ';Server=' . $servidor . ';Database=BANCO_APP'
                 . ($confiaCert ? ';TrustServerCertificate=yes' : '')
                 . ($usuario === '' ? ';Trusted_Connection=yes' : '');
            $this->charset = self::env('EVASAO_ODBC_CHARSET', PHP_OS_FAMILY === 'Windows' ? 'Windows-1252' : 'UTF-8');
            if ($this->charset !== 'UTF-8' && !function_exists('mb_convert_encoding') && !function_exists('iconv')) {
                throw new ErroBanco('pdo_odbc com ' . $this->charset . ' precisa da extensao mbstring ou iconv');
            }
        } else {
            throw new ErroBanco('EVASAO_PDO_DRIVER invalido: use auto, sqlsrv ou odbc');
        }

        try {
            // usuario/senha como argumento, nao no DSN: senha com ";" quebraria o DSN
            $this->pdo = new PDO($dsn, $usuario === '' ? null : $usuario, $senha === '' ? null : $senha, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new ErroBanco($e->getMessage(), 0, $e);
        }
    }

    /** conexao.php numa das pastas acima desta (app-web/<pasta>/api/lib/ -> app-web/). */
    private static function acharConexaoPhp(): ?string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 5; $i++) {
            $pai = dirname($dir);
            if ($pai === $dir) {
                break;
            }
            $dir = $pai;
            if (is_file($dir . DIRECTORY_SEPARATOR . 'conexao.php')) {
                return $dir . DIRECTORY_SEPARATOR . 'conexao.php';
            }
        }
        return null;
    }

    /** Usa a conexao dos outros apps do app-web: Conexao::getConnection('instituicao') = BANCO_APP. */
    private function conectarPeloApp(string $arquivo): void
    {
        try {
            require_once $arquivo;
            if (!class_exists('Conexao') || !method_exists('Conexao', 'getConnection')) {
                throw new ErroBanco($arquivo . ' nao define Conexao::getConnection');
            }
            $pdo = Conexao::getConnection('instituicao');
        } catch (ErroBanco $e) {
            throw $e;
        } catch (Throwable $e) {
            // a Conexao do app-web embrulha o PDOException num Exception generico
            throw new ErroBanco($e->getMessage(), 0, $e);
        }
        if (!$pdo instanceof PDO) {
            throw new ErroBanco('Conexao::getConnection nao devolveu um PDO');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
        // o conexao.php usa pdo_sqlsrv (textos em UTF-8); se um dia for pdo_odbc, converte
        $this->charset = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'odbc'
            ? self::env('EVASAO_ODBC_CHARSET', PHP_OS_FAMILY === 'Windows' ? 'Windows-1252' : 'UTF-8')
            : 'UTF-8';
    }

    /**
     * Executa um SELECT com parametros posicionais (?) e devolve as linhas em
     * UTF-8. Todo parametro vai como texto: quem chama converte inteiros para o
     * SQL literal depois de validar (TOP/OFFSET nao aceitam texto em todo driver).
     *
     * @param array<int, string> $params
     * @return array<int, array<string, mixed>>
     */
    public function consultar(string $sql, array $params = []): array
    {
        try {
            $st = $this->pdo->prepare($this->paraBanco($sql));
            foreach (array_values($params) as $i => $p) {
                $st->bindValue($i + 1, $this->paraBanco((string) $p), PDO::PARAM_STR);
            }
            $st->execute();
            // explicito: a conexao do app-web nao define o modo padrao (viria FETCH_BOTH)
            $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
        } catch (PDOException $e) {
            throw new ErroBanco($e->getMessage(), 0, $e);
        }
        if ($this->charset === 'UTF-8') {
            return $linhas;
        }
        foreach ($linhas as &$linha) {
            foreach ($linha as &$v) {
                if (is_string($v)) {
                    $v = self::converter($v, $this->charset, 'UTF-8');
                }
            }
            unset($v);
        }
        unset($linha);
        return $linhas;
    }

    private function paraBanco(string $s): string
    {
        return $this->charset === 'UTF-8' ? $s : self::converter($s, 'UTF-8', $this->charset);
    }

    private static function converter(string $s, string $de, string $para): string
    {
        if ($s === '' || preg_match('/[\x80-\xFF]/', $s) !== 1) {
            return $s;   // ASCII puro: igual nos dois charsets
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($s, $para, $de);
        }
        $r = iconv($de, $para . '//TRANSLIT', $s);
        return $r === false ? $s : $r;
    }
}
