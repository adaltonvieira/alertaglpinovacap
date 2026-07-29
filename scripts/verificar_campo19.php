<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\GLPI\GlpiClient;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$glpiConfig = require dirname(__DIR__) . '/config/glpi.php';
$glpi = new GlpiClient($glpiConfig);

$glpi->initSession();

$opcoes = $glpi->listarSearchOptions('Ticket');

echo "Campo 19: " . var_export($opcoes[19] ?? 'NAO EXISTE', true) . "\n\n";

echo "Todos os campos que contem atualiz, modif ou update:\n";
foreach ($opcoes as $numero => $info) {
    if (!is_array($info)) {
        continue;
    }
    $nome = $info['name'] ?? '';
    if (stripos($nome, 'atualiz') !== false || stripos($nome, 'modif') !== false || stripos($nome, 'update') !== false) {
        echo "Campo {$numero}: {$nome}\n";
    }
}

$glpi->killSession();
