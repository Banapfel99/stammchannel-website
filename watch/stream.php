<?php

declare(strict_types=1);

/**
 * Server-Sent Events stream for a Watch Together room.
 *
 * Delivers authoritative state changes, queue updates and chat messages to a
 * connected client. The client subscribes via EventSource and POSTs actions to
 * watch/actions.php. This endpoint is read-only apart from refreshing the
 * caller's presence heartbeat.
 *
 * Session note: we read the user id and immediately release the session lock
 * (session_write_close) so this long-lived request does not block the user's
 * other requests (actions.php runs under the same PHP session).
 */

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/watch.php';

requireLogin();

$userId = (int) $_SESSION['user_id'];
$roomId = (int) ($_GET['room_id'] ?? 0);
$lastMessageId = (int) ($_GET['last_message_id'] ?? 0);

// Release the session lock: the rest of this script needs no session writes.
session_write_close();

if (!watchSchemaReady($pdo) || watchGetRoom($pdo, $roomId) === null) {
    http_response_code(404);
    exit;
}

// Presence is established here so refreshing/reconnecting keeps the user in the room.
watchJoinRoom($pdo, $roomId, $userId);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // disable nginx proxy buffering for this stream

// Avoid PHP output buffering swallowing the stream.
while (ob_get_level() > 0) {
    ob_end_flush();
}

@set_time_limit(0);
ignore_user_abort(false);

/**
 * @param array<string, mixed> $payload
 */
function sseSend(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload) . "\n\n";

    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        @ob_flush();
    }

    flush();
}

$lastStateVersion = -1;
$lastQueueVersion = -1;

// The stream lives for a bounded time; EventSource reconnects automatically.
// This keeps php-fpm workers from being held indefinitely.
$deadline = time() + 55;

// Poll interval in microseconds. 1.5s keeps WebSocket-like feel without high
// database frequency (well within the "no unnecessarily high frequency" goal).
$pollIntervalUs = 1_500_000;

while (time() < $deadline) {
    if (connection_aborted()) {
        break;
    }

    $room = watchGetRoom($pdo, $roomId);

    if ($room === null) {
        sseSend('room_closed', ['room_id' => $roomId]);
        break;
    }

    // Refresh presence and make sure the room always has a live host.
    watchTouchParticipant($pdo, $roomId, $userId);
    watchReconcileRoom($pdo, $roomId);

    // Re-read after reconcile (host may have changed).
    $room = watchGetRoom($pdo, $roomId);

    if ($room === null) {
        sseSend('room_closed', ['room_id' => $roomId]);
        break;
    }

    $stateVersion = (int) $room['state_version'];
    $queueVersion = (int) $room['queue_version'];

    if ($stateVersion !== $lastStateVersion) {
        sseSend('state', watchRoomStateJson($pdo, $room));
        $lastStateVersion = $stateVersion;
    }

    if ($queueVersion !== $lastQueueVersion) {
        sseSend('queue', ['items' => watchGetQueue($pdo, $roomId)]);
        $lastQueueVersion = $queueVersion;
    }

    // Presence changes (join/leave/online-timeout) aren't versioned, so send a
    // lightweight viewer/host snapshot every tick — it's a single cheap query.
    sseSend('presence', [
        'viewer_count' => watchOnlineCount($pdo, $roomId),
        'participants' => watchGetParticipants($pdo, $roomId),
        'host_id' => (int) $room['host_id'],
    ]);

    $newMessages = watchGetMessages($pdo, $roomId, $lastMessageId);

    foreach ($newMessages as $message) {
        sseSend('chat', [
            'id' => (int) $message['id'],
            'user_id' => $message['user_id'] !== null ? (int) $message['user_id'] : null,
            'username' => $message['username'],
            'body' => $message['body'],
            'created_at' => $message['created_at'],
        ]);
        $lastMessageId = (int) $message['id'];
    }

    usleep($pollIntervalUs);
}

// Ask the browser to reconnect promptly after we intentionally close.
echo "retry: 2000\n\n";
flush();
