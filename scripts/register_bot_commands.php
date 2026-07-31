<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Telegram\TelegramClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$telegram = new TelegramClient(getenv('TELEGRAM_BOT_TOKEN'));

$comandos = [
    ['command' => 'meuschamados', 'description' => 'Seus chamados em aberto'],
    ['command' => 'criticos',     'description' => 'Chamados com prioridade critica'],
    ['command' => 'atrasados',    'description' => 'Chamados com SLA vencido'],
    ['command' => 'hoje',         'description' => 'Chamados abertos hoje'],
    ['command' => 'sla',          'description' => 'Seus chamados por SLA restante'],
    ['command' => 'painel',       'description' => 'Resumo geral do sistema'],
    ['command' => 'id',           'description' => 'Mostra seu ID do Telegram'],
];

$resultado = $telegram->setMyCommands($comandos);

fwrite(STDOUT, json_encode($resultado, JSON_PRETTY_PRINT) . "\n");

if ($resultado['ok'] ?? false) {
    fwrite(STDOUT, "\nComandos registrados com sucesso.\n");
} else {
    fwrite(STDERR, "\nFalha ao registrar comandos.\n");
    exit(1);
}
