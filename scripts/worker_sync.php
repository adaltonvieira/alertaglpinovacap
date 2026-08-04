<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\GLPI\GlpiClient;
use App\Services\SlaEngine;
use App\Services\MessageFormatter;
use App\Services\NotificationDispatcher;
use App\Telegram\TelegramClient;
use App\Workers\GlpiSyncWorker;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

function criarConexaoDb(): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$db = criarConexaoDb();

$redis = new Predis\Client([
    'host'     => getenv('REDIS_HOST'),
    'port'     => getenv('REDIS_PORT'),
    'password' => getenv('REDIS_PASSWORD'),
]);

$glpiConfig = require dirname(__DIR__) . '/config/glpi.php';
$glpi = new GlpiClient($glpiConfig);

$sla = new SlaEngine();
$formatter = new MessageFormatter($sla);
$telegram = new TelegramClient(getenv('TELEGRAM_BOT_TOKEN'));
$dispatcher = new NotificationDispatcher($db, $redis, $telegram);

$worker = new GlpiSyncWorker($glpi, $db, $sla, $formatter, $dispatcher, $glpiConfig);

// Roda em loop continuo (nao depende do cron - motivo: o crontab as vezes
// falha silenciosamente por causa do PATH restrito do cron nao achar o
// binario do PHP). O container inteiro fica de pe rodando este script.
while (true) {
    try {
        $worker->executar();
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] Sincronizacao concluida.\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERRO: ' . $e->getMessage() . "\n");

        // Se foi problema de conexao com o banco (ex.: "MySQL server has
        // gone away" apos ficar ocioso), reconecta antes da proxima
        // tentativa - senao o mesmo erro se repete para sempre.
        try {
            $db = criarConexaoDb();
            $dispatcher = new NotificationDispatcher($db, $redis, $telegram);
            $worker = new GlpiSyncWorker($glpi, $db, $sla, $formatter, $dispatcher, $glpiConfig);
            fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] Reconectado ao banco com sucesso.\n");
        } catch (\Throwable $e2) {
            fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Falha ao reconectar: ' . $e2->getMessage() . "\n");
        }
    }

    sleep(20);
}
