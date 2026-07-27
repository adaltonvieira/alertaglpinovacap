<?php

/**
 * Aplica os arquivos SQL de database/migrations/ em ordem alfabetica.
 * Idempotente: usa "CREATE TABLE IF NOT EXISTS", portanto pode ser
 * executado multiplas vezes sem duplicar nada.
 *
 * Uso: php scripts/migrate.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$database = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

if (!$host || !$database || !$username) {
    fwrite(STDERR, "ERRO: variaveis DB_HOST/DB_DATABASE/DB_USERNAME nao configuradas.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$database}`");
} catch (\Throwable $e) {
    fwrite(STDERR, "ERRO ao conectar no banco: {$e->getMessage()}\n");
    exit(1);
}

$migrationsDir = dirname(__DIR__) . '/database/migrations';
$arquivos = glob($migrationsDir . '/*.sql');
sort($arquivos);

if (empty($arquivos)) {
    fwrite(STDOUT, "Nenhum arquivo de migracao encontrado em {$migrationsDir}.\n");
    exit(0);
}

foreach ($arquivos as $arquivo) {
    fwrite(STDOUT, "Aplicando " . basename($arquivo) . " ... ");

    $sql = file_get_contents($arquivo);
    $sqlSemComentarios = preg_replace('/^--.*$/m', '', $sql);

    try {
        $statements = array_filter(
            array_map('trim', explode(';', $sqlSemComentarios)),
            fn($s) => $s !== ''
        );

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        fwrite(STDOUT, "OK\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "FALHOU: {$e->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "\n Migracao concluida com sucesso.\n");