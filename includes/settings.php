<?php

declare(strict_types=1);

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    $statement = $pdo->prepare(
        'SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1'
    );

    $statement->execute(['key' => $key]);

    $value = $statement->fetchColumn();

    return $value !== false ? (string) $value : $default;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = :value2'
    );

    $statement->execute([
        'key' => $key,
        'value' => $value,
        'value2' => $value,
    ]);
}

function getMaxPlaylistsPerUser(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'max_playlists_per_user', '3'));
}


