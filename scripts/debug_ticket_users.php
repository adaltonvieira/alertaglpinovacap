<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\GLPI\GlpiClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$ticketId = (int) ($argv[1] ?? 0);

if ($ticketId <= 0) {
    echo "Uso: php debug_ticket_users.php <ID_DO_CHAMADO>\n";
    exit(1);
}

$glpiConfig = require dirname(__DIR__) . '/config/glpi.php';
$glpi = new GlpiClient($glpiConfig);

$glpi->initSession();

echo "Buscando vinculos de usuario do chamado #{$ticketId}...\n\n";

$vinculos = $glpi->getTicketUsers($ticketId);

echo "===== RESPOSTA CRUA =====\n";
var_export($vinculos);
echo "\n===== FIM =====\n";

$glpi->killSession();
