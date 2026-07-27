<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\NotificationDispatcher;
use App\Telegram\TelegramClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$redis = new Predis\Client([
    'host'     => getenv('REDIS_HOST'),
    'port'     => getenv('REDIS_PORT'),
    'password' => getenv('REDIS_PASSWORD'),
]);

$telegram = new TelegramClient(getenv('TELEGRAM_BOT_TOKEN'));
$dispatcher = new NotificationDispatcher($db, $redis, $telegram);

// Processo contínuo (worker de longa duração). Em Docker, o container
// reinicia automaticamente (restart: unless-stopped) em caso de falha.
while (true) {
    $processou = $dispatcher->processarProximo();

    if (!$processou) {
        usleep(500_000); // 0.5s — evita busy-loop quando a fila está vazia
    }
}
