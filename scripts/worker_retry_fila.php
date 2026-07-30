<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Predis\Client as RedisClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$redis = new RedisClient([
    'host'     => getenv('REDIS_HOST'),
    'port'     => getenv('REDIS_PORT'),
    'password' => getenv('REDIS_PASSWORD'),
]);

while (true) {
    try {
        $stmt = $db->query(
            "SELECT id, payload_json FROM fila_mensagens
             WHERE status = 'pendente'
               AND tentativas > 0
               AND tentativas < max_tentativas
               AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW())
             LIMIT 20"
        );
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($linhas as $linha) {
            $payload = json_decode($linha['payload_json'], true);
            $payload['fila_id'] = (int) $linha['id'];
            $redis->rpush('fila:notificacoes', json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        if (!empty($linhas)) {
            fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] Reenfileiradas ' . count($linhas) . " mensagem(ns) pendente(s).\n");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERRO: ' . $e->getMessage() . "\n");
    }

    sleep(30);
}
