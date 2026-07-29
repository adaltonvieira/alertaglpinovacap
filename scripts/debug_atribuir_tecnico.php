<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\GLPI\GlpiClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$ticketId = (int) ($argv[1] ?? 0);
$userId = (int) ($argv[2] ?? 0);

if ($ticketId <= 0 || $userId <= 0) {
    echo "Uso: php debug_atribuir_tecnico.php <ID_DO_CHAMADO_GLPI> <ID_DO_USUARIO_GLPI>\n";
    exit(1);
}

$glpiConfig = require dirname(__DIR__) . '/config/glpi.php';
$glpi = new GlpiClient($glpiConfig);

$glpi->initSession();

echo "Tentando atribuir usuario {$userId} ao chamado {$ticketId}...\n\n";

try {
    $resultado = $glpi->atribuirTecnico($ticketId, $userId);
    echo "===== RESPOSTA CRUA =====\n";
    var_export($resultado);
    echo "\n===== FIM =====\n";
} catch (\Throwable $e) {
    echo "EXCECAO: " . $e->getMessage() . "\n";
}

$glpi->killSession();
