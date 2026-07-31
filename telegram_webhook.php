<?php

require __DIR__ . '/vendor/autoload.php';

use App\GLPI\GlpiClient;
use App\Services\MessageFormatter;
use App\Services\NotificationDispatcher;
use App\Services\SlaEngine;
use App\Telegram\BotCommands;
use App\Telegram\TelegramClient;
use App\Telegram\WebhookHandler;
use Predis\Client as RedisClient;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

header('Content-Type: application/json');

$secretRecebido = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$secretEsperado = getenv('TELEGRAM_WEBHOOK_SECRET') ?: '';

if ($secretEsperado === '' || !hash_equals($secretEsperado, $secretRecebido)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid secret']);
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!is_array($update)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

try {
    $db = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $glpiConfig = require __DIR__ . '/config/glpi.php';
    $glpi = new GlpiClient($glpiConfig);

    $redis = new RedisClient([
        'host'     => getenv('REDIS_HOST'),
        'port'     => getenv('REDIS_PORT'),
        'password' => getenv('REDIS_PASSWORD'),
    ]);

    $sla = new SlaEngine();
    $telegram = new TelegramClient(getenv('TELEGRAM_BOT_TOKEN'));
    $formatter = new MessageFormatter($sla);
    $dispatcher = new NotificationDispatcher($db, $redis, $telegram);
    $botCommands = new BotCommands($db, $telegram, $sla);

    $handler = new WebhookHandler($db, $glpi, $telegram, $sla, $formatter, $dispatcher, $botCommands);
    $handler->processar($update);

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    error_log('[telegram_webhook] ERRO: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'internal error, logged']);
}
