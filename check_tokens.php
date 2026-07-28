<?php
$t = getenv("GLPI_APP_TOKEN");
echo "Tamanho do GLPI_APP_TOKEN: " . strlen($t) . " caracteres\n";
echo "Tem espaco no inicio/fim? " . (trim($t) !== $t ? "SIM - PROBLEMA!" : "Nao") . "\n";
echo "Tem quebra de linha? " . ((strpos($t, chr(10)) !== false || strpos($t, chr(13)) !== false) ? "SIM - PROBLEMA!" : "Nao") . "\n";

$t2 = getenv("GLPI_USER_TOKEN");
echo "Tamanho do GLPI_USER_TOKEN: " . strlen($t2) . " caracteres\n";
echo "Tem espaco no inicio/fim? " . (trim($t2) !== $t2 ? "SIM - PROBLEMA!" : "Nao") . "\n";