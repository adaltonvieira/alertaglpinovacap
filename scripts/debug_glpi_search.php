<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\GLPI\GlpiClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$glpiConfig = require dirname(__DIR__) . '/config/glpi.php';
$glpi = new GlpiClient($glpiConfig);

echo "Iniciando sessao...\n";
$glpi->initSession();
echo "Sessao OK.\n\n";

$desde = new DateTimeImmutable('2020-01-01 00:00:00');

echo "Buscando chamados modificados desde: {$desde->format('Y-m-d H:i:s')}\n\n";

$resultado = $glpi->buscarTicketsAtualizadosDesde($desde);

echo "===== RESPOSTA CRUA DA API =====\n";
var_export($resultado);
echo "\n===== FIM DA RESPOSTA =====\n\n";

if (isset($resultado['data'])) {
    echo "Quantidade de chamados encontrados: " . count($resultado['data']) . "\n";
} else {
    echo "AVISO: a resposta nao tem a chave data - ver estrutura acima.\n";
}

$glpi->killSession();
