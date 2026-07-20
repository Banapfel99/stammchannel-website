<?php

declare(strict_types=1);

const MUSIC_UPLOAD_DIR = __DIR__ . '/../uploads/music';
const MUSIC_AUDIO_DIR = MUSIC_UPLOAD_DIR . '/audio';
const MUSIC_COVER_DIR = MUSIC_UPLOAD_DIR . '/covers';

const MUSIC_ALLOWED_AUDIO_MIME = [
    'audio/mpeg' => 'mp3',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/aac' => 'aac',
    'audio/ogg' => 'ogg',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/webm' => 'weba',
];

const MUSIC_ALLOWED_COVER_MIME = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

const MUSIC_DEFAULT_MAX_AUDIO_MB = 60;
const MUSIC_DEFAULT_MAX_COVER_MB = 5;

function getMaxAudioUploadMb(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'max_audio_upload_mb', (string) MUSIC_DEFAULT_MAX_AUDIO_MB));
}

function getMaxCoverUploadMb(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'max_cover_upload_mb', (string) MUSIC_DEFAULT_MAX_COVER_MB));
}

function getMaxAudioUploadBytes(PDO $pdo): int
{
    return getMaxAudioUploadMb($pdo) * 1024 * 1024;
}

function getMaxCoverUploadBytes(PDO $pdo): int
{
    return getMaxCoverUploadMb($pdo) * 1024 * 1024;
}

function ensureMusicDirectories(): void
{
    foreach ([MUSIC_UPLOAD_DIR, MUSIC_AUDIO_DIR, MUSIC_COVER_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

function musicRandomFilename(string $extension): string
{
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

function countUserPlaylists(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM playlists WHERE owner_id = :owner_id'
    );

    $statement->execute(['owner_id' => $userId]);

    return (int) $statement->fetchColumn();
}

function parseSpotifyUrl(string $url): ?array
{
    if (
        preg_match(
            '#^https://open\.spotify\.com/(playlist|track|album)/([A-Za-z0-9]+)(?:\?.*)?$#',
            trim($url),
            $matches
        )
    ) {
        return [
            'type' => $matches[1],
            'ref_id' => $matches[2],
        ];
    }

    return null;
}

function spotifyEmbedUrl(string $type, string $refId): string
{
    return sprintf(
        'https://open.spotify.com/embed/%s/%s',
        rawurlencode($type),
        rawurlencode($refId)
    );
}

function getPlaylistOrFail(PDO $pdo, int $playlistId): ?array
{
    $statement = $pdo->prepare(
        'SELECT p.id, p.owner_id, p.name, p.created_at, u.username AS owner_username
         FROM playlists p
         JOIN users u ON u.id = p.owner_id
         WHERE p.id = :id
         LIMIT 1'
    );

    $statement->execute(['id' => $playlistId]);

    $playlist = $statement->fetch();

    return $playlist === false ? null : $playlist;
}

function ensureListenRoom(PDO $pdo, int $playlistId): void
{
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO listen_rooms (playlist_id) VALUES (:playlist_id)'
    );

    $statement->execute(['playlist_id' => $playlistId]);
}

function musicSchemaReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->query('SELECT 1 FROM playlists LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }

    return $ready;
}

function appSettingsSchemaReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->query('SELECT 1 FROM app_settings LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }

    return $ready;
}

function requireMusicSchema(PDO $pdo): void
{
    if (musicSchemaReady($pdo)) {
        return;
    }

    http_response_code(503);

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<title>Musik nicht verfügbar</title>'
        . '<link rel="stylesheet" href="/assets/css/style.css"></head><body>'
        . '<main class="content"><h1>Musik-Funktion ist noch nicht eingerichtet</h1>'
        . '<p>Die Datenbanktabellen für das Musik-Widget fehlen. Bitte führe '
        . '<code>database/music_schema.sql</code> einmalig gegen die Datenbank aus, '
        . 'dann lade diese Seite erneut.</p>'
        . '<p><a href="/dashboard.php">Zurück zum Dashboard</a></p>'
        . '</main></body></html>';

    exit;
}
