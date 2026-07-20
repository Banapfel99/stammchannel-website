<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/music.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!musicSchemaReady($pdo)) {
    http_response_code(503);
    echo json_encode(['error' => 'Musik-Funktion ist noch nicht eingerichtet.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function jsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function jsonOk(array $data = []): void
{
    echo json_encode(['ok' => true] + $data);
    exit;
}

if ($action === 'state') {
    $playlistId = (int) ($_GET['playlist_id'] ?? 0);

    if ($playlistId <= 0 || getPlaylistOrFail($pdo, $playlistId) === null) {
        jsonError('Playlist nicht gefunden.', 404);
    }

    ensureListenRoom($pdo, $playlistId);
    touchListenPresence($pdo, $playlistId, $userId);

    $statement = $pdo->prepare(
        'SELECT current_track_id, position_seconds, is_playing, shuffle, updated_at
         FROM listen_rooms
         WHERE playlist_id = :playlist_id
         LIMIT 1'
    );
    $statement->execute(['playlist_id' => $playlistId]);
    $room = $statement->fetch();

    jsonOk([
        'room' => [
            'current_track_id' => $room['current_track_id'] !== null ? (int) $room['current_track_id'] : null,
            'position_seconds' => (float) $room['position_seconds'],
            'is_playing' => (bool) $room['is_playing'],
            'shuffle' => (bool) $room['shuffle'],
            'updated_at' => $room['updated_at'],
            'server_time' => date('Y-m-d H:i:s'),
        ],
        'listeners' => getActiveListeners($pdo, $playlistId),
    ]);
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    jsonError('Ungültige Anfrage.', 403);
}

if ($action === 'update') {
    $playlistId = (int) ($_POST['playlist_id'] ?? 0);

    if ($playlistId <= 0 || getPlaylistOrFail($pdo, $playlistId) === null) {
        jsonError('Playlist nicht gefunden.', 404);
    }

    ensureListenRoom($pdo, $playlistId);

    $currentTrackId = isset($_POST['current_track_id']) && $_POST['current_track_id'] !== ''
        ? (int) $_POST['current_track_id']
        : null;

    if ($currentTrackId !== null) {
        $trackCheck = $pdo->prepare(
            'SELECT id FROM tracks WHERE id = :id AND playlist_id = :playlist_id LIMIT 1'
        );
        $trackCheck->execute(['id' => $currentTrackId, 'playlist_id' => $playlistId]);

        if ($trackCheck->fetch() === false) {
            jsonError('Titel gehört nicht zu dieser Playlist.', 400);
        }
    }

    $positionSeconds = (float) ($_POST['position_seconds'] ?? 0);
    $isPlaying = (int) (bool) ($_POST['is_playing'] ?? false);
    $shuffle = (int) (bool) ($_POST['shuffle'] ?? false);

    $statement = $pdo->prepare(
        'UPDATE listen_rooms
         SET current_track_id = :current_track_id,
             position_seconds = :position_seconds,
             is_playing = :is_playing,
             shuffle = :shuffle,
             updated_by = :updated_by
         WHERE playlist_id = :playlist_id'
    );

    $statement->execute([
        'current_track_id' => $currentTrackId,
        'position_seconds' => $positionSeconds,
        'is_playing' => $isPlaying,
        'shuffle' => $shuffle,
        'updated_by' => $userId,
        'playlist_id' => $playlistId,
    ]);

    jsonOk();
}

if ($action === 'record_play') {
    $trackId = (int) ($_POST['track_id'] ?? 0);

    $trackCheck = $pdo->prepare('SELECT id FROM tracks WHERE id = :id LIMIT 1');
    $trackCheck->execute(['id' => $trackId]);

    if ($trackCheck->fetch() === false) {
        jsonError('Titel nicht gefunden.', 404);
    }

    $statement = $pdo->prepare(
        'INSERT INTO track_plays (track_id, user_id) VALUES (:track_id, :user_id)'
    );
    $statement->execute(['track_id' => $trackId, 'user_id' => $userId]);

    jsonOk();
}

jsonError('Unbekannte Aktion.', 400);
