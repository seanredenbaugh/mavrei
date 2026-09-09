<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo "Maverick database check\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "PDO MySQL: " . (extension_loaded('pdo_mysql') ? 'loaded' : 'NOT LOADED') . "\n";

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    exit("Result: config.php was not found in public_html\n");
}

try {
    $config = require $configFile;
    $database = $config['database'] ?? [];
    foreach (['host', 'port', 'name', 'username', 'password', 'charset'] as $key) {
        if (!array_key_exists($key, $database) || $database[$key] === '') {
            exit("Result: database.$key is missing or empty in config.php\n");
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $database['host'],
        $database['port'],
        $database['name'],
        $database['charset']
    );
    $pdo = new PDO($dsn, $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn();
    echo "Connection: SUCCESS\n";
    echo "Properties: $count\n";
} catch (Throwable $error) {
    echo "Connection: FAILED\n";
    echo "Error code: " . $error->getCode() . "\n";
    echo "Error: " . $error->getMessage() . "\n";
}

echo "\nDelete db-check.php after copying this result.\n";
