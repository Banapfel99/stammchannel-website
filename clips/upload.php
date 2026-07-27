<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/clips.php';

requireLogin();

requireClipsSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$userId = (int) $_SESSION['user_id'];

function redirectWithError(string $message): void
{
    header('Location: /clips/index.php?msg=' . urlencode($message) . '&tab=upload');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectWithError('Ungültige Anfrage.');
}

// Simple upload cooldown to avoid accidental/abusive rapid-fire uploads.
if (countRecentClipUploads($pdo, $userId, 10) > 0) {
    redirectWithError('Bitte warte kurz, bevor du den nächsten Clip hochlädst.');
}

$title = trim($_POST['title'] ?? '');

if ($title === '' || mb_strlen($title) > 150) {
    redirectWithError('Bitte gib einen gültigen Titel ein (max. 150 Zeichen).');
}

$gameName = trim($_POST['game_name'] ?? '');

if (mb_strlen($gameName) > 100) {
    $gameName = mb_substr($gameName, 0, 100);
}

if (!isset($_FILES['clip']) || $_FILES['clip']['error'] !== UPLOAD_ERR_OK) {
    redirectWithError('Bitte wähle eine gültige Videodatei aus.');
}

$clipFile = $_FILES['clip'];
$maxUploadBytes = getMaxClipUploadBytes($pdo);

if ($clipFile['size'] > $maxUploadBytes) {
    redirectWithError('Die Videodatei ist zu groß (max. ' . getMaxClipUploadMb($pdo) . ' MB).');
}

// Never trust the file extension — detect the real container type from the
// file's magic bytes (finfo reads the actual file signature).
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = (string) $finfo->file($clipFile['tmp_name']);

if (!array_key_exists($detectedMime, CLIP_ALLOWED_INPUT_MIME)) {
    redirectWithError('Dieses Videoformat wird nicht unterstützt.');
}

if (!ensureClipDirectories()) {
    redirectWithError('Upload-Verzeichnis ist nicht beschreibbar. Bitte Server-Administrator kontaktieren.');
}

$tempExtension = CLIP_ALLOWED_INPUT_MIME[$detectedMime];
$tempFilename = clipGenerateUuid() . '.' . $tempExtension;
$tempPath = clipTempDir() . '/' . $tempFilename;

if (!move_uploaded_file($clipFile['tmp_name'], $tempPath)) {
    error_log('StammClips-Upload: move_uploaded_file fehlgeschlagen. Ziel: ' . $tempPath);
    redirectWithError('Videodatei konnte nicht gespeichert werden.');
}

$maxDuration = getMaxClipDurationSeconds($pdo);
$result = processUploadedClip($tempPath, $maxDuration);

if (!$result['ok']) {
    redirectWithError($result['error'] ?? 'Der Clip konnte nicht verarbeitet werden.');
}

$statement = $pdo->prepare(
    'INSERT INTO clips
        (uploader_id, title, game_name, filename, mime, duration_seconds, width, height, status, rand_seed, processed_at)
     VALUES
        (:uploader_id, :title, :game_name, :filename, :mime, :duration_seconds, :width, :height, \'ready\', :rand_seed, NOW())'
);

$statement->execute([
    'uploader_id' => $userId,
    'title' => $title,
    'game_name' => $gameName !== '' ? $gameName : null,
    'filename' => $result['filename'],
    'mime' => 'video/mp4',
    'duration_seconds' => $result['duration'],
    'width' => $result['width'],
    'height' => $result['height'],
    'rand_seed' => clipRandomSeed(),
]);

header('Location: /clips/index.php?msg=' . urlencode('Clip wurde hochgeladen und verarbeitet.'));
exit;
