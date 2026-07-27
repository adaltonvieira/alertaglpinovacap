<?php

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

header('Content-Type: text/plain; charset=utf-8');

function mascarar(?string $valor): string
{
    if (!$valor) {
        return '(vazio)';
    }
    $tam = strlen($valor);
    if ($tam <= 8) {
        return str_repeat('*', $tam);
    }
    return substr($valor, 0, 4) . str_repeat('*', $tam - 8) . substr($valor, -4);
}

echo "===== DIAGNOSTICO DE CONECTIVIDADE =====\n";
echo "Horario: " . date('Y-m-d H:i:s') . "\n\n";

echo "--- MySQL ---\n";
echo "DB_HOST = " . getenv('DB_HOST') . "\n";
echo "DB_DATABASE = " . getenv('DB_DATABASE') . "\n";
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    $qtdTabelas = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    echo "OK Conectado. Tabelas encontradas: {$qtdTabelas}\n";
} catch (\Throwable $e) {
    echo "FALHOU: " . $e->getMessage() . "\n";
}

echo "\n";

echo "--- Redis ---\n";
echo "REDIS_HOST = " . getenv('REDIS_HOST') . "\n";
try {
    $redis = new Predis\Client([
        'host'     => getenv('REDIS_HOST'),
        'port'     => getenv('REDIS_PORT'),
        'password' => getenv('REDIS_PASSWORD'),
        'timeout'  => 5,
    ]);
    $pong = $redis->ping();
    echo "OK Conectado. Resposta do PING: " . (is_object($pong) ? 'PONG' : $pong) . "\n";
} catch (\Throwable $e) {
    echo "FALHOU: " . $e->getMessage() . "\n";
}

echo "\n";

echo "--- GLPI ---\n";
echo "GLPI_BASE_URL = " . getenv('GLPI_BASE_URL') . "\n";
echo "GLPI_APP_TOKEN = " . mascarar(getenv('GLPI_APP_TOKEN')) . "\n";
echo "GLPI_USER_TOKEN = " . mascarar(getenv('GLPI_USER_TOKEN')) . "\n";
try {
    $glpi = new App\GLPI\GlpiClient([
        'base_url'   => getenv('GLPI_BASE_URL'),
        'app_token'  => getenv('GLPI_APP_TOKEN'),
        'user_token' => getenv('GLPI_USER_TOKEN'),
        'timeout'    => 10,
    ]);
    $glpi->initSession();
    echo "OK initSession() funcionou - App Token e User Token validos.\n";
    $glpi->killSession();
} catch (\Throwable $e) {
    echo "FALHOU: " . $e->getMessage() . "\n";
}

echo "\n";

echo "--- Telegram ---\n";
echo "TELEGRAM_BOT_TOKEN = " . mascarar(getenv('TELEGRAM_BOT_TOKEN')) . "\n";
try {
    $ch = curl_init("https://api.telegram.org/bot" . getenv('TELEGRAM_BOT_TOKEN') . "/getMe");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);

    if ($data['ok'] ?? false) {
        echo "OK Bot valido: @" . $data['result']['username'] . " (" . $data['result']['first_name'] . ")\n";
    } else {
        echo "FALHOU: " . ($resp ?: 'sem resposta') . "\n";
    }
} catch (\Throwable $e) {
    echo "FALHOU: " . $e->getMessage() . "\n";
}

echo "\n";

echo "--- Grupo 'Novo Chamado' (Telegram) ---\n";
$grupoId = getenv('TELEGRAM_GRUPO_NOVO_CHAMADO_ID');
echo "TELEGRAM_GRUPO_NOVO_CHAMADO_ID = " . ($grupoId ?: '(vazio)') . "\n";
if ($grupoId && str_starts_with($grupoId, '-')) {
    echo "OK Formato parece correto (chat_id de grupo comeca com '-').\n";
} elseif ($grupoId) {
    echo "ATENCAO: Chat IDs de grupo normalmente sao negativos (ex: -1001234567890). Confirme se pegou o ID certo.\n";
} else {
    echo "FALHOU: Ainda nao configurado.\n";
}

echo "\n===== FIM DO DIAGNOSTICO =====\n";