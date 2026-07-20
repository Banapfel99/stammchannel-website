<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/music.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$userId = (int) $_SESSION['user_id'];
$playlistId = (int) ($_POST['playlist_id'] ?? 0);

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$playlist = $playlistId > 0 ? getPlaylistOrFail($pdo, $playlistId) : null;

if ($playlist === null) {
    http_response_code(404);
    exit('Playlist nicht gefunden.');
}

function redirectWithError(int $playlistId, string $message): void
{
    header('Location: /music/playlist.php?id=' . $playlistId . '&msg=' . urlencode($message));
    exit;
}

$title = trim($_POST['title'] ?? '');

if ($title === '' || mb_strlen($title) > 150) {
    redirectWithError($playlistId, 'Bitte gib einen gültigen Titel ein.');
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    redirectWithError($playlistId, 'Bitte wähle eine gültige Audiodatei aus.');
}

$audioFile = $_FILES['audio'];

$maxAudioBytes = getMaxAudioUploadBytes($pdo);

if ($audioFile['size'] > $maxAudioBytes) {
    redirectWithError($playlistId, 'Die Audiodatei ist zu groß (max. ' . getMaxAudioUploadMb($pdo) . ' MB).');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$audioMime = (string) $finfo->file($audioFile['tmp_name']);

if (!array_key_exists($audioMime, MUSIC_ALLOWED_AUDIO_MIME)) {
    redirectWithError($playlistId, 'Dieses Audioformat wird nicht unterstützt.');
}

$coverFilename = null;
$coverMime = null;

if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
    $coverFile = $_FILES['cover'];

    $maxCoverBytes = getMaxCoverUploadBytes($pdo);

    if ($coverFile['size'] > $maxCoverBytes) {
        redirectWithError($playlistId, 'Das Cover-Bild ist zu groß (max. ' . getMaxCoverUploadMb($pdo) . ' MB).');
    }

    $detectedCoverMime = (string) $finfo->file($coverFile['tmp_name']);

    if (!array_key_exists($detectedCoverMime, MUSIC_ALLOWED_COVER_MIME)) {
        redirectWithError($playlistId, 'Dieses Cover-Format wird nicht unterstützt.');
    }

    ensureMusicDirectories();

    $coverExtension = MUSIC_ALLOWED_COVER_MIME[$detectedCoverMime];
    $coverFilename = musicRandomFilename($coverExtension);
    $coverDestination = MUSIC_COVER_DIR . '/' . $coverFilename;

    if (!move_uploaded_file($coverFile['tmp_name'], $coverDestination)) {
        redirectWithError($playlistId, 'Cover konnte nicht gespeichert werden.');
    }

    $coverMime = $detectedCoverMime;
}

ensureMusicDirectories();

$audioExtension = MUSIC_ALLOWED_AUDIO_MIME[$audioMime];
$audioFilename = musicRandomFilename($audioExtension);
$audioDestination = MUSIC_AUDIO_DIR . '/' . $audioFilename;

if (!move_uploaded_file($audioFile['tmp_name'], $audioDestination)) {
    redirectWithError($playlistId, 'Audiodatei konnte nicht gespeichert werden.');
}

$statement = $pdo->prepare(
    'INSERT INTO tracks
        (playlist_id, uploader_id, title, audio_filename, audio_mime, cover_filename, cover_mime, sort_order)
     VALUES
        (:playlist_id, :uploader_id, :title, :audio_filename, :audio_mime, :cover_filename, :cover_mime,
         (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tracks t2 WHERE t2.playlist_id = :playlist_id2))'
);

$statement->execute([
    'playlist_id' => $playlistId,
    'playlist_id2' => $playlistId,
    'uploader_id' => $userId,
    'title' => $title,
    'audio_filename' => $audioFilename,
    'audio_mime' => $audioMime,
    'cover_filename' => $coverFilename,
    'cover_mime' => $coverMime,
]);

header('Location: /music/playlist.php?id=' . $playlistId . '&msg=' . urlencode('Titel wurde hochgeladen.'));
exit;
