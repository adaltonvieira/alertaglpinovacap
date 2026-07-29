
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\GLPI\GlpiClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));

$dotenv->safeLoad();

$ticketId = (int) ($argv[1] ?? 0);

if ($ticketId <= 0) {

    echo "Uso: php debug_ticket_campos_sla.php <ID_DO_CHAMADO>\n";

    exit(1);

}

$glpiConfig = require dirname(__DIR__) . '/config/glpi.php';

$glpi = new GlpiClient($glpiConfig);

$glpi->initSession();

$ticket = $glpi->getTicket($ticketId);

echo "Campos relevantes para o calculo de SLA:\n\n";

foreach (['priority', 'impact', 'urgency', 'type', 'date', 'status', 'groups_id_assign'] as $campo) {

    $valor = $ticket[$campo] ?? '(nao existe)';

    echo str_pad($campo, 20) . ": " . var_export($valor, true) . " [tipo: " . gettype($valor) . "]\n";

}

$glpi->killSession();

