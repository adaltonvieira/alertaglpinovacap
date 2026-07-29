<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\GLPI\GlpiClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$glpiConfig = require dirname(__DIR__) . '/config/glpi.php';
$glpi = new GlpiClient($glpiConfig);

$glpi->initSession();

$opcoes = $glpi->listarSearchOptions('Ticket');

echo "Campos que contem 'ID' ou 'data' no nome:\n\n";
foreach ($opcoes as $numero => $info) {
    if (!is_array($info)) {
        continue;
    }
    $nome = $info['name'] ?? '';
    if (stripos($nome, 'ID') !== false || stripos($nome, 'data') !== false || stripos($nome, 'modif') !== false) {
        echo "Campo {$numero}: {$nome}\n";
    }
}

$glpi->killSession();
