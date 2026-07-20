<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/music.php';

requireLogin();

$type = $_GET['type'] ?? '';
$trackId = (int) ($_GET['id'] ?? 0);

if (!in_array($type, ['audio', 'cover'], true) || $trackId <= 0) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$statement = $pdo->prepare(
    'SELECT audio_filename, audio_mime, cover_filename, cover_mime
     FROM tracks
     WHERE id = :id
     LIMIT 1'
);
$statement->execute(['id' => $trackId]);
$track = $statement->fetch();

if ($track === false) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

if ($type === 'audio') {
    $filename = $track['audio_filename'];
    $mime = $track['audio_mime'];
    $baseDir = MUSIC_AUDIO_DIR;
} else {
    $filename = $track['cover_filename'];
    $mime = $track['cover_mime'];
    $baseDir = MUSIC_COVER_DIR;
}

if ($filename === null) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$path = $baseDir . '/' . basename($filename);

if (!is_file($path)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$size = filesize($path);
$start = 0;
$end = $size - 1;

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');

if ($type === 'audio' && isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        if ($matches[1] !== '') {
            $start = (int) $matches[1];
        }

        if ($matches[2] !== '') {
            $end = (int) $matches[2];
        }

        $end = min($end, $size - 1);

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
}

header('Content-Length: ' . ($end - $start + 1));
header('Content-Disposition: inline; filename="' . basename($filename) . '"');

$handle = fopen($path, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit('Datei konnte nicht gelesen werden.');
}

fseek($handle, $start);
$bytesToSend = $end - $start + 1;

while ($bytesToSend > 0 && !feof($handle)) {
    $chunkSize = min(8192, $bytesToSend);
    echo fread($handle, $chunkSize);
    $bytesToSend -= $chunkSize;
    flush();
}

fclose($handle);
