<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/clips.php';

requireLogin();

requireClipsSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$userId = (int) $_SESSION['user_id'];

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$clipId = (int) ($_POST['clip_id'] ?? 0);

$statement = $pdo->prepare('SELECT filename, uploader_id FROM clips WHERE id = :id LIMIT 1');
$statement->execute(['id' => $clipId]);
$clip = $statement->fetch();

if ($clip === false) {
    header('Location: /clips/index.php?msg=' . urlencode('Clip nicht gefunden.'));
    exit;
}

if (!isAdmin() && (int) $clip['uploader_id'] !== $userId) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$deleteStatement = $pdo->prepare('DELETE FROM clips WHERE id = :id');
$deleteStatement->execute(['id' => $clipId]);

$path = clipProcessedDir() . '/' . basename($clip['filename']);

if (is_file($path)) {
    unlink($path);
}

header('Location: /clips/index.php?msg=' . urlencode('Clip wurde gelöscht.'));
exit;
