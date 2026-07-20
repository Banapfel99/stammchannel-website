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

const MUSIC_MAX_AUDIO_BYTES = 60 * 1024 * 1024;
const MUSIC_MAX_COVER_BYTES = 5 * 1024 * 1024;

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
