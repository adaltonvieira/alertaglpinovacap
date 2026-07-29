<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Telegram\TelegramClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$url = $argv[1] ?? null;

if (!$url) {
    fwrite(STDERR, "Uso: php scripts/register_telegram_webhook.php https://seu-dominio/telegram_webhook.php\n");
    exit(1);
}

$secret = getenv('TELEGRAM_WEBHOOK_SECRET');

if (!$secret) {
    fwrite(STDERR, "ERRO: TELEGRAM_WEBHOOK_SECRET nao configurado no .env\n");
    exit(1);
}

$telegram = new TelegramClient(getenv('TELEGRAM_BOT_TOKEN'));

$resultado = $telegram->setWebhook($url, $secret);

fwrite(STDOUT, "Resultado:\n");
fwrite(STDOUT, json_encode($resultado, JSON_PRETTY_PRINT) . "\n");

if ($resultado['ok'] ?? false) {
    fwrite(STDOUT, "\nWebhook registrado com sucesso em: {$url}\n");
} else {
    fwrite(STDERR, "\nFalha ao registrar webhook.\n");
    exit(1);
}
