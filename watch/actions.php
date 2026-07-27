<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/watch.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!watchSchemaReady($pdo)) {
    http_response_code(503);
    echo json_encode(['error' => 'Watch Together ist noch nicht eingerichtet.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function watchJsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function watchJsonOk(array $data = []): void
{
    echo json_encode(['ok' => true] + $data);
    exit;
}

// All mutating actions require a valid CSRF token.
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    watchJsonError('Ungültige Anfrage.', 403);
}

$roomId = (int) ($_POST['room_id'] ?? 0);

/**
 * Loads the room or fails. Also verifies membership for room-scoped actions.
 */
function watchRequireRoom(PDO $pdo, int $roomId): array
{
    $room = watchGetRoom($pdo, $roomId);

    if ($room === null) {
        watchJsonError('Raum nicht gefunden.', 404);
    }

    return $room;
}

function watchRequireHost(PDO $pdo, int $roomId, int $userId): void
{
    if (!watchIsHost($pdo, $roomId, $userId)) {
        watchJsonError('Nur der Host darf das steuern.', 403);
    }
}

switch ($action) {
    case 'create':
        $name = (string) ($_POST['name'] ?? '');
        $newRoomId = watchCreateRoom($pdo, $userId, $name);
        watchJsonOk(['room_id' => $newRoomId]);
        break;

    case 'join':
        watchRequireRoom($pdo, $roomId);
        watchJoinRoom($pdo, $roomId, $userId);
        watchJsonOk(['room_id' => $roomId]);
        break;

    case 'leave':
        watchRequireRoom($pdo, $roomId);
        watchLeaveRoom($pdo, $roomId, $userId);
        watchJsonOk();
        break;

    case 'add_queue':
        watchRequireRoom($pdo, $roomId);

        if (!watchIsParticipant($pdo, $roomId, $userId)) {
            watchJsonError('Du bist nicht in diesem Raum.', 403);
        }

        $result = watchAddQueueItem($pdo, $roomId, $userId, (string) ($_POST['url'] ?? ''));

        if (!$result['ok']) {
            watchJsonError($result['error'] ?? 'Video konnte nicht hinzugefügt werden.');
        }

        watchJsonOk(['item_id' => $result['item_id'] ?? null]);
        break;

    case 'remove_queue':
        watchRequireRoom($pdo, $roomId);
        $itemId = (int) ($_POST['item_id'] ?? 0);

        if (!watchRemoveQueueItem($pdo, $roomId, $itemId, $userId)) {
            watchJsonError('Video konnte nicht entfernt werden.', 403);
        }

        watchJsonOk();
        break;

    case 'set_current':
        watchRequireRoom($pdo, $roomId);
        watchRequireHost($pdo, $roomId, $userId);
        $itemId = (int) ($_POST['item_id'] ?? 0);

        // Verify the item belongs to this room.
        $check = $pdo->prepare('SELECT 1 FROM watch_queue_items WHERE id = :id AND room_id = :room_id LIMIT 1');
        $check->execute(['id' => $itemId, 'room_id' => $roomId]);

        if ($check->fetch() === false) {
            watchJsonError('Video nicht gefunden.', 404);
        }

        watchSetCurrentItem($pdo, $roomId, $itemId);
        watchJsonOk();
        break;

    case 'playback':
        watchRequireRoom($pdo, $roomId);
        watchRequireHost($pdo, $roomId, $userId);

        $state = (string) ($_POST['state'] ?? '');
        $position = (float) ($_POST['position'] ?? 0);

        if (!in_array($state, ['playing', 'paused'], true)) {
            watchJsonError('Ungültiger Wiedergabestatus.');
        }

        watchSetPlayback($pdo, $roomId, $state, $position);
        watchJsonOk();
        break;

    case 'skip':
        watchRequireRoom($pdo, $roomId);
        watchRequireHost($pdo, $roomId, $userId);
        watchAdvanceQueue($pdo, $roomId);
        watchJsonOk();
        break;

    case 'chat':
        watchRequireRoom($pdo, $roomId);

        if (!watchIsParticipant($pdo, $roomId, $userId)) {
            watchJsonError('Du bist nicht in diesem Raum.', 403);
        }

        $result = watchAddMessage($pdo, $roomId, $userId, (string) ($_POST['body'] ?? ''));

        if (!$result['ok']) {
            watchJsonError($result['error'] ?? 'Nachricht konnte nicht gesendet werden.', 429);
        }

        watchJsonOk(['id' => $result['id'] ?? null]);
        break;

    default:
        watchJsonError('Unbekannte Aktion.', 400);
}
