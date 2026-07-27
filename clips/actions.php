<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/clips.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!clipsSchemaReady($pdo)) {
    http_response_code(503);
    echo json_encode(['error' => 'StammClips ist noch nicht eingerichtet.']);
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

function clipToJson(PDO $pdo, array $clip, int $userId): array
{
    return [
        'id' => (int) $clip['id'],
        'title' => $clip['title'],
        'game_name' => $clip['game_name'],
        'uploader' => $clip['uploader_username'],
        'created_at' => $clip['created_at'],
        'relative_time' => clipRelativeTime($clip['created_at']),
        'duration_seconds' => (float) $clip['duration_seconds'],
        'video_url' => '/clips/file.php?id=' . (int) $clip['id'],
        'reactions' => getClipReactionCounts($pdo, (int) $clip['id']),
        'user_reactions' => getUserClipReactions($pdo, (int) $clip['id'], $userId),
        'views' => getClipViewCount($pdo, (int) $clip['id']),
    ];
}

if ($action === 'random') {
    // Session-scoped list of recently shown clip ids, so the same person
    // doesn't keep seeing the same clip within one browsing session.
    $recent = $_SESSION['clips_recent'] ?? [];

    if (!is_array($recent)) {
        $recent = [];
    }

    $clip = pickRandomClip($pdo, $recent);

    if ($clip === null) {
        jsonOk(['clip' => null]);
    }

    $recent[] = (int) $clip['id'];
    $_SESSION['clips_recent'] = array_slice(array_values(array_unique($recent)), -6);

    jsonOk(['clip' => clipToJson($pdo, $clip, $userId)]);
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    jsonError('Ungültige Anfrage.', 403);
}

if ($action === 'react') {
    $clipId = (int) ($_POST['clip_id'] ?? 0);
    $reactionType = (string) ($_POST['reaction_type'] ?? '');

    $exists = $pdo->prepare("SELECT id FROM clips WHERE id = :id AND status = 'ready' LIMIT 1");
    $exists->execute(['id' => $clipId]);

    if ($exists->fetch() === false) {
        jsonError('Clip nicht gefunden.', 404);
    }

    if (!in_array($reactionType, CLIP_REACTION_TYPES, true)) {
        jsonError('Unbekannter Reaktionstyp.', 400);
    }

    $active = toggleClipReaction($pdo, $clipId, $userId, $reactionType);

    jsonOk([
        'active' => $active,
        'reactions' => getClipReactionCounts($pdo, $clipId),
    ]);
}

if ($action === 'view') {
    $clipId = (int) ($_POST['clip_id'] ?? 0);

    $exists = $pdo->prepare("SELECT id FROM clips WHERE id = :id AND status = 'ready' LIMIT 1");
    $exists->execute(['id' => $clipId]);

    if ($exists->fetch() === false) {
        jsonError('Clip nicht gefunden.', 404);
    }

    recordClipView($pdo, $clipId, $userId);

    jsonOk(['views' => getClipViewCount($pdo, $clipId)]);
}

jsonError('Unbekannte Aktion.', 400);
