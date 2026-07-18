<?php

declare(strict_types=1);

$configFile = __DIR__ . '/../config/config.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Website-Konfiguration fehlt.');
}

$config = require $configFile;

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db']['host'],
            $config['db']['database']
        ),
        $config['db']['username'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Datenbankverbindung fehlgeschlagen.');
}