<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/music.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$userId = (int) $_SESSION['user_id'];

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$target = $_POST['target'] ?? '';
$playlistId = (int) ($_POST['playlist_id'] ?? 0);

$playlist = $playlistId > 0 ? getPlaylistOrFail($pdo, $playlistId) : null;

if ($playlist === null) {
    http_response_code(404);
    exit('Playlist nicht gefunden.');
}

$canManage = isAdmin() || $playlist['owner_id'] === $userId;

if ($target === 'track') {
    $trackId = (int) ($_POST['track_id'] ?? 0);

    $statement = $pdo->prepare(
        'SELECT audio_filename, cover_filename, uploader_id
         FROM tracks
         WHERE id = :id AND playlist_id = :playlist_id
         LIMIT 1'
    );
    $statement->execute(['id' => $trackId, 'playlist_id' => $playlistId]);
    $track = $statement->fetch();

    if ($track === false) {
        header('Location: /music/playlist.php?id=' . $playlistId . '&msg=' . urlencode('Titel nicht gefunden.'));
        exit;
    }

    if (!$canManage && (int) $track['uploader_id'] !== $userId) {
        http_response_code(403);
        exit('Zugriff verweigert.');
    }

    $deleteStatement = $pdo->prepare('DELETE FROM tracks WHERE id = :id');
    $deleteStatement->execute(['id' => $trackId]);

    $audioPath = MUSIC_AUDIO_DIR . '/' . basename($track['audio_filename']);

    if (is_file($audioPath)) {
        unlink($audioPath);
    }

    if ($track['cover_filename'] !== null) {
        $coverPath = MUSIC_COVER_DIR . '/' . basename($track['cover_filename']);

        if (is_file($coverPath)) {
            unlink($coverPath);
        }
    }

    header('Location: /music/playlist.php?id=' . $playlistId . '&msg=' . urlencode('Titel wurde gelöscht.'));
    exit;
}

if ($target === 'spotify') {
    $linkId = (int) ($_POST['link_id'] ?? 0);

    $statement = $pdo->prepare(
        'SELECT added_by FROM playlist_spotify_links WHERE id = :id AND playlist_id = :playlist_id LIMIT 1'
    );
    $statement->execute(['id' => $linkId, 'playlist_id' => $playlistId]);
    $link = $statement->fetch();

    if ($link === false) {
        header('Location: /music/playlist.php?id=' . $playlistId . '&msg=' . urlencode('Spotify-Link nicht gefunden.'));
        exit;
    }

    if (!$canManage && (int) $link['added_by'] !== $userId) {
        http_response_code(403);
        exit('Zugriff verweigert.');
    }

    $statement = $pdo->prepare(
        'DELETE FROM playlist_spotify_links WHERE id = :id AND playlist_id = :playlist_id'
    );
    $statement->execute(['id' => $linkId, 'playlist_id' => $playlistId]);

    header('Location: /music/playlist.php?id=' . $playlistId . '&msg=' . urlencode('Spotify-Link wurde entfernt.'));
    exit;
}

if ($target === 'playlist') {
    if (!$canManage) {
        http_response_code(403);
        exit('Zugriff verweigert.');
    }

    $statement = $pdo->prepare(
        'SELECT audio_filename, cover_filename FROM tracks WHERE playlist_id = :playlist_id'
    );
    $statement->execute(['playlist_id' => $playlistId]);
    $tracks = $statement->fetchAll();

    $deleteStatement = $pdo->prepare('DELETE FROM playlists WHERE id = :id');
    $deleteStatement->execute(['id' => $playlistId]);

    foreach ($tracks as $track) {
        $audioPath = MUSIC_AUDIO_DIR . '/' . basename($track['audio_filename']);

        if (is_file($audioPath)) {
            unlink($audioPath);
        }

        if ($track['cover_filename'] !== null) {
            $coverPath = MUSIC_COVER_DIR . '/' . basename($track['cover_filename']);

            if (is_file($coverPath)) {
                unlink($coverPath);
            }
        }
    }

    header('Location: /music/index.php?msg=' . urlencode('Playlist wurde gelöscht.'));
    exit;
}

http_response_code(400);
exit('Unbekannte Aktion.');
