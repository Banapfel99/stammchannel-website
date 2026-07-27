<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/clips.php';

requireLogin();

requireClipsSchema($pdo);

$clipId = (int) ($_GET['id'] ?? 0);

if ($clipId <= 0) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$statement = $pdo->prepare(
    "SELECT filename, mime, status FROM clips WHERE id = :id LIMIT 1"
);
$statement->execute(['id' => $clipId]);
$clip = $statement->fetch();

if ($clip === false || $clip['status'] !== 'ready') {
    http_response_code(404);
    exit('Clip nicht gefunden.');
}

$path = clipProcessedDir() . '/' . basename($clip['filename']);

if (!is_file($path)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$size = filesize($path);
$start = 0;
$end = $size - 1;

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $clip['mime']);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
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

header('Content-Length: ' . ($end - $start + 1));
header('Content-Disposition: inline; filename="' . basename($clip['filename']) . '"');

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
