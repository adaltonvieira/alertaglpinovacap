<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SlaEngine;
use App\Services\MessageFormatter;
use App\Services\NotificationDispatcher;
use App\Telegram\TelegramClient;
use App\Workers\SlaMonitorWorker;

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

$sla = new SlaEngine();
$formatter = new MessageFormatter($sla);
$telegram = new TelegramClient(getenv('TELEGRAM_BOT_TOKEN'));
$dispatcher = new NotificationDispatcher($db, $redis, $telegram);

$worker = new SlaMonitorWorker($db, $sla, $formatter, $dispatcher);

while (true) {
    try {
        $worker->executar();
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] Monitoramento de SLA concluido.\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERRO: ' . $e->getMessage() . "\n");
    }

    sleep(60);
}
