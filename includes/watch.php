<?php

declare(strict_types=1);

/**
 * Watch Together backend logic.
 *
 * The server is the authoritative source for room state. Clients POST actions
 * (watch/actions.php) and subscribe to a Server-Sent-Events stream
 * (watch/stream.php). Only YouTube video IDs are ever stored — never arbitrary
 * embed markup — and all rendered strings are escaped at the output layer.
 */

// A participant is considered "online" if their SSE connection touched the row
// within this window. Used for the viewer count and host reconciliation.
const WATCH_PRESENCE_TIMEOUT_SECONDS = 20;

const WATCH_CHAT_DEFAULT_MAX_LENGTH = 500;

// Simple per-user chat throttle (seconds between messages) enforced server-side.
const WATCH_CHAT_MIN_INTERVAL_SECONDS = 1;

function watchSchemaReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->query('SELECT 1 FROM watch_rooms LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }

    return $ready;
}

function requireWatchSchema(PDO $pdo): void
{
    if (watchSchemaReady($pdo)) {
        return;
    }

    require_once __DIR__ . '/assets.php';

    http_response_code(503);

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<title>Watch Together nicht verfügbar</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(asset('/assets/css/style.css')) . '"></head><body>'
        . '<main class="content"><h1>Watch Together ist noch nicht eingerichtet</h1>'
        . '<p>Die Datenbanktabellen für Watch Together fehlen. Bitte führe '
        . '<code>database/watch_schema.sql</code> einmalig gegen die Datenbank aus, '
        . 'dann lade diese Seite erneut.</p>'
        . '<p><a href="/dashboard.php">Zurück zum Dashboard</a></p>'
        . '</main></body></html>';

    exit;
}

/**
 * Extracts and validates an 11-character YouTube video ID from a raw user
 * input (full URL, short URL, embed URL or bare ID). Returns null if no valid
 * ID can be derived. We deliberately never store arbitrary URLs or embed codes.
 */
function watchExtractYoutubeId(string $input): ?string
{
    $input = trim($input);

    if ($input === '') {
        return null;
    }

    // Bare ID: must be exactly 11 valid characters, nothing more.
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $input) === 1) {
        return $input;
    }

    // Anything else must be a YouTube URL. Reject arbitrary hosts so a
    // "?v=" query parameter on some other domain can never be accepted.
    if (preg_match('~^https?://~i', $input) !== 1) {
        return null;
    }

    if (preg_match('~://(?:[a-z0-9-]+\.)*(?:youtube\.com|youtube-nocookie\.com|youtu\.be)/~i', $input) !== 1) {
        return null;
    }

    // Each pattern captures exactly 11 id characters and asserts the next
    // character is NOT part of an id, so an over-long token is rejected
    // instead of being silently truncated to its first 11 characters.
    $patterns = [
        '~[?&]v=([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~',       // watch?v=ID
        '~youtu\.be/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~',   // youtu.be/ID
        '~/embed/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~',      // /embed/ID
        '~/shorts/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~',     // /shorts/ID
        '~/live/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~',       // /live/ID
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $matches) === 1) {
            return $matches[1];
        }
    }

    return null;
}

/**
 * Best-effort server-side lookup of a YouTube video title via the public
 * oEmbed endpoint. Never throws; returns null on any failure or when outbound
 * network access is unavailable.
 */
function watchFetchYoutubeTitle(string $youtubeId): ?string
{
    $url = 'https://www.youtube.com/oembed?format=json&url='
        . rawurlencode('https://www.youtube.com/watch?v=' . $youtubeId);

    $context = stream_context_create([
        'http' => ['timeout' => 3, 'ignore_errors' => true],
        'https' => ['timeout' => 3, 'ignore_errors' => true],
    ]);

    $json = @file_get_contents($url, false, $context);

    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);

    if (!is_array($data) || empty($data['title']) || !is_string($data['title'])) {
        return null;
    }

    return mb_substr(trim($data['title']), 0, 255);
}

function watchChatMaxLength(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'watch_chat_message_max_length', (string) WATCH_CHAT_DEFAULT_MAX_LENGTH));
}

/**
 * Creates a new room, makes the creator the host and their first participant.
 * Returns the new room id.
 */
function watchCreateRoom(PDO $pdo, int $hostId, string $name): int
{
    $name = trim($name);

    if ($name === '') {
        $name = 'Watch Party';
    }

    $name = mb_substr($name, 0, 120);

    $statement = $pdo->prepare(
        'INSERT INTO watch_rooms (name, host_id, position_updated_at)
         VALUES (:name, :host_id, NOW())'
    );
    $statement->execute(['name' => $name, 'host_id' => $hostId]);

    $roomId = (int) $pdo->lastInsertId();

    watchJoinRoom($pdo, $roomId, $hostId);

    return $roomId;
}

/**
 * @return array<string, mixed>|null
 */
function watchGetRoom(PDO $pdo, int $roomId): ?array
{
    $statement = $pdo->prepare(
        'SELECT r.*, u.username AS host_username,
                qi.youtube_id AS current_youtube_id, qi.title AS current_title
         FROM watch_rooms r
         JOIN users u ON u.id = r.host_id
         LEFT JOIN watch_queue_items qi ON qi.id = r.current_item_id
         WHERE r.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $roomId]);
    $room = $statement->fetch();

    return $room === false ? null : $room;
}

/**
 * Lists rooms that currently have at least one online participant, most recent
 * first. Used by the overview page and the dashboard widget.
 *
 * @return array<int, array<string, mixed>>
 */
function watchListActiveRooms(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT r.id, r.name, r.host_id, u.username AS host_username,
                r.playback_state, r.current_item_id,
                qi.youtube_id AS current_youtube_id, qi.title AS current_title,
                (SELECT COUNT(*) FROM watch_participants p
                 WHERE p.room_id = r.id
                   AND p.last_seen_at >= (NOW() - INTERVAL :timeout SECOND)) AS viewer_count
         FROM watch_rooms r
         JOIN users u ON u.id = r.host_id
         LEFT JOIN watch_queue_items qi ON qi.id = r.current_item_id
         HAVING viewer_count > 0
         ORDER BY r.created_at DESC'
    );
    $statement->bindValue('timeout', WATCH_PRESENCE_TIMEOUT_SECONDS, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function watchBumpStateVersion(PDO $pdo, int $roomId): void
{
    $pdo->prepare('UPDATE watch_rooms SET state_version = state_version + 1 WHERE id = :id')
        ->execute(['id' => $roomId]);
}

function watchBumpQueueVersion(PDO $pdo, int $roomId): void
{
    $pdo->prepare('UPDATE watch_rooms SET queue_version = queue_version + 1 WHERE id = :id')
        ->execute(['id' => $roomId]);
}

function watchJoinRoom(PDO $pdo, int $roomId, int $userId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO watch_participants (room_id, user_id, joined_at, last_seen_at)
         VALUES (:room_id, :user_id, NOW(), NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
    );
    $statement->execute(['room_id' => $roomId, 'user_id' => $userId]);

    watchBumpStateVersion($pdo, $roomId);
}

/**
 * Refreshes a participant's presence heartbeat. Called on each SSE tick.
 */
function watchTouchParticipant(PDO $pdo, int $roomId, int $userId): void
{
    $statement = $pdo->prepare(
        'UPDATE watch_participants SET last_seen_at = NOW()
         WHERE room_id = :room_id AND user_id = :user_id'
    );
    $statement->execute(['room_id' => $roomId, 'user_id' => $userId]);
}

/**
 * Removes a participant from a room. If they were the host, a new host is
 * promoted automatically. If the room becomes empty it is deleted.
 */
function watchLeaveRoom(PDO $pdo, int $roomId, int $userId): void
{
    $pdo->prepare('DELETE FROM watch_participants WHERE room_id = :room_id AND user_id = :user_id')
        ->execute(['room_id' => $roomId, 'user_id' => $userId]);

    watchReconcileRoom($pdo, $roomId, $userId);
}

/**
 * Ensures the room has a valid, present host and cleans up empty rooms.
 * $leftUserId (optional) is a user who just left and must not be re-selected.
 */
function watchReconcileRoom(PDO $pdo, int $roomId, ?int $leftUserId = null): void
{
    $room = watchGetRoom($pdo, $roomId);

    if ($room === null) {
        return;
    }

    // Any participants left at all?
    $remaining = (int) $pdo->query(
        'SELECT COUNT(*) FROM watch_participants WHERE room_id = ' . $roomId
    )->fetchColumn();

    if ($remaining === 0) {
        // No one left — tear the room down.
        $pdo->prepare('DELETE FROM watch_rooms WHERE id = :id')->execute(['id' => $roomId]);

        return;
    }

    // Is the current host still present (online)?
    $hostPresent = $pdo->prepare(
        'SELECT 1 FROM watch_participants
         WHERE room_id = :room_id AND user_id = :host_id
           AND last_seen_at >= (NOW() - INTERVAL :timeout SECOND)
         LIMIT 1'
    );
    $hostPresent->bindValue('room_id', $roomId, PDO::PARAM_INT);
    $hostPresent->bindValue('host_id', (int) $room['host_id'], PDO::PARAM_INT);
    $hostPresent->bindValue('timeout', WATCH_PRESENCE_TIMEOUT_SECONDS, PDO::PARAM_INT);
    $hostPresent->execute();

    $hostLeft = $leftUserId !== null && (int) $room['host_id'] === $leftUserId;

    if (!$hostLeft && $hostPresent->fetch() !== false) {
        return;
    }

    // Promote the longest-present online participant (fallback: any remaining).
    $candidate = $pdo->prepare(
        'SELECT user_id FROM watch_participants
         WHERE room_id = :room_id AND user_id <> :old_host
           AND last_seen_at >= (NOW() - INTERVAL :timeout SECOND)
         ORDER BY joined_at ASC
         LIMIT 1'
    );
    $candidate->bindValue('room_id', $roomId, PDO::PARAM_INT);
    $candidate->bindValue('old_host', (int) $room['host_id'], PDO::PARAM_INT);
    $candidate->bindValue('timeout', WATCH_PRESENCE_TIMEOUT_SECONDS, PDO::PARAM_INT);
    $candidate->execute();
    $newHostId = $candidate->fetchColumn();

    if ($newHostId === false) {
        // No online candidate — keep whoever is technically still in the room.
        $newHostId = $pdo->prepare(
            'SELECT user_id FROM watch_participants
             WHERE room_id = :room_id AND user_id <> :old_host
             ORDER BY joined_at ASC LIMIT 1'
        );
        $newHostId->bindValue('room_id', $roomId, PDO::PARAM_INT);
        $newHostId->bindValue('old_host', (int) $room['host_id'], PDO::PARAM_INT);
        $newHostId->execute();
        $newHostId = $newHostId->fetchColumn();
    }

    if ($newHostId !== false) {
        $pdo->prepare('UPDATE watch_rooms SET host_id = :host_id WHERE id = :id')
            ->execute(['host_id' => (int) $newHostId, 'id' => $roomId]);
        watchBumpStateVersion($pdo, $roomId);
    }
}

function watchIsHost(PDO $pdo, int $roomId, int $userId): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM watch_rooms WHERE id = :id AND host_id = :host_id LIMIT 1');
    $statement->execute(['id' => $roomId, 'host_id' => $userId]);

    return $statement->fetch() !== false;
}

function watchIsParticipant(PDO $pdo, int $roomId, int $userId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM watch_participants WHERE room_id = :room_id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute(['room_id' => $roomId, 'user_id' => $userId]);

    return $statement->fetch() !== false;
}

/**
 * @return array<int, array<string, mixed>>
 */
function watchGetParticipants(PDO $pdo, int $roomId): array
{
    $statement = $pdo->prepare(
        'SELECT p.user_id, u.username,
                (p.last_seen_at >= (NOW() - INTERVAL :timeout SECOND)) AS online
         FROM watch_participants p
         JOIN users u ON u.id = p.user_id
         WHERE p.room_id = :room_id
         ORDER BY p.joined_at ASC'
    );
    $statement->bindValue('timeout', WATCH_PRESENCE_TIMEOUT_SECONDS, PDO::PARAM_INT);
    $statement->bindValue('room_id', $roomId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function watchOnlineCount(PDO $pdo, int $roomId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM watch_participants
         WHERE room_id = :room_id AND last_seen_at >= (NOW() - INTERVAL :timeout SECOND)'
    );
    $statement->bindValue('room_id', $roomId, PDO::PARAM_INT);
    $statement->bindValue('timeout', WATCH_PRESENCE_TIMEOUT_SECONDS, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/**
 * Adds a validated YouTube video to the room queue. Any user may add. If the
 * room has no current video, the freshly added item becomes current.
 *
 * @return array{ok: bool, error?: string, item_id?: int}
 */
function watchAddQueueItem(PDO $pdo, int $roomId, int $userId, string $rawInput): array
{
    $youtubeId = watchExtractYoutubeId($rawInput);

    if ($youtubeId === null) {
        return ['ok' => false, 'error' => 'Keine gültige YouTube-Video-URL erkannt.'];
    }

    $title = watchFetchYoutubeTitle($youtubeId);

    $nextOrder = (int) $pdo->query(
        'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM watch_queue_items WHERE room_id = ' . $roomId
    )->fetchColumn();

    $statement = $pdo->prepare(
        'INSERT INTO watch_queue_items (room_id, youtube_id, title, added_by, sort_order)
         VALUES (:room_id, :youtube_id, :title, :added_by, :sort_order)'
    );
    $statement->execute([
        'room_id' => $roomId,
        'youtube_id' => $youtubeId,
        'title' => $title,
        'added_by' => $userId,
        'sort_order' => $nextOrder,
    ]);

    $itemId = (int) $pdo->lastInsertId();

    // If nothing is playing yet, make this the current video.
    $room = watchGetRoom($pdo, $roomId);

    if ($room !== null && $room['current_item_id'] === null) {
        watchSetCurrentItem($pdo, $roomId, $itemId);
    }

    watchBumpQueueVersion($pdo, $roomId);

    return ['ok' => true, 'item_id' => $itemId];
}

/**
 * @return array<int, array<string, mixed>>
 */
function watchGetQueue(PDO $pdo, int $roomId): array
{
    $statement = $pdo->prepare(
        'SELECT qi.id, qi.youtube_id, qi.title, qi.sort_order, qi.added_by,
                u.username AS added_by_username
         FROM watch_queue_items qi
         LEFT JOIN users u ON u.id = qi.added_by
         WHERE qi.room_id = :room_id
         ORDER BY qi.sort_order ASC'
    );
    $statement->execute(['room_id' => $roomId]);

    return $statement->fetchAll();
}

/**
 * Removes a queue item. Only the host or the user who added it may remove it.
 * If the removed item was the current video, playback advances to the next.
 */
function watchRemoveQueueItem(PDO $pdo, int $roomId, int $itemId, int $userId): bool
{
    $statement = $pdo->prepare(
        'SELECT added_by FROM watch_queue_items WHERE id = :id AND room_id = :room_id LIMIT 1'
    );
    $statement->execute(['id' => $itemId, 'room_id' => $roomId]);
    $item = $statement->fetch();

    if ($item === false) {
        return false;
    }

    if (!watchIsHost($pdo, $roomId, $userId) && (int) $item['added_by'] !== $userId) {
        return false;
    }

    $room = watchGetRoom($pdo, $roomId);
    $wasCurrent = $room !== null && (int) $room['current_item_id'] === $itemId;

    if ($wasCurrent) {
        // Pick the next item (by order) before deleting the current one.
        $next = $pdo->prepare(
            'SELECT id FROM watch_queue_items
             WHERE room_id = :room_id AND sort_order > (
                 SELECT sort_order FROM watch_queue_items WHERE id = :id
             )
             ORDER BY sort_order ASC LIMIT 1'
        );
        $next->execute(['room_id' => $roomId, 'id' => $itemId]);
        $nextId = $next->fetchColumn();

        $pdo->prepare('UPDATE watch_rooms SET current_item_id = NULL WHERE id = :id')
            ->execute(['id' => $roomId]);
    }

    $pdo->prepare('DELETE FROM watch_queue_items WHERE id = :id')->execute(['id' => $itemId]);

    if ($wasCurrent) {
        watchSetCurrentItem($pdo, $roomId, isset($nextId) && $nextId !== false ? (int) $nextId : null);
    }

    watchBumpQueueVersion($pdo, $roomId);

    return true;
}

/**
 * Sets the current video and resets the playback head. Host-only action is
 * enforced by the caller. Passing null clears the current video.
 */
function watchSetCurrentItem(PDO $pdo, int $roomId, ?int $itemId): void
{
    $statement = $pdo->prepare(
        'UPDATE watch_rooms
         SET current_item_id = :item_id,
             playback_position = 0,
             playback_state = :state,
             position_updated_at = NOW()
         WHERE id = :id'
    );
    $statement->execute([
        'item_id' => $itemId,
        'state' => $itemId === null ? 'paused' : 'playing',
        'id' => $roomId,
    ]);

    watchBumpStateVersion($pdo, $roomId);
}

/**
 * Advances to the next queue item after the current one (host-only, enforced
 * by caller). Wraps to null when at the end.
 */
function watchAdvanceQueue(PDO $pdo, int $roomId): void
{
    $room = watchGetRoom($pdo, $roomId);

    if ($room === null) {
        return;
    }

    $nextId = null;

    if ($room['current_item_id'] !== null) {
        $next = $pdo->prepare(
            'SELECT id FROM watch_queue_items
             WHERE room_id = :room_id AND sort_order > (
                 SELECT sort_order FROM watch_queue_items WHERE id = :id
             )
             ORDER BY sort_order ASC LIMIT 1'
        );
        $next->execute(['room_id' => $roomId, 'id' => (int) $room['current_item_id']]);
        $result = $next->fetchColumn();
        $nextId = $result === false ? null : (int) $result;
    } else {
        $first = $pdo->prepare(
            'SELECT id FROM watch_queue_items WHERE room_id = :room_id ORDER BY sort_order ASC LIMIT 1'
        );
        $first->execute(['room_id' => $roomId]);
        $result = $first->fetchColumn();
        $nextId = $result === false ? null : (int) $result;
    }

    watchSetCurrentItem($pdo, $roomId, $nextId);
}

/**
 * Updates the authoritative playback state (host-only, enforced by caller).
 * $state is 'playing' or 'paused'; $position is the head in seconds.
 */
function watchSetPlayback(PDO $pdo, int $roomId, string $state, float $position): void
{
    if (!in_array($state, ['playing', 'paused'], true)) {
        throw new InvalidArgumentException('Ungültiger Wiedergabestatus.');
    }

    $statement = $pdo->prepare(
        'UPDATE watch_rooms
         SET playback_state = :state,
             playback_position = :position,
             position_updated_at = NOW()
         WHERE id = :id'
    );
    $statement->execute([
        'state' => $state,
        'position' => max(0, $position),
        'id' => $roomId,
    ]);

    watchBumpStateVersion($pdo, $roomId);
}

/**
 * Computes the live playback head the server believes the room should be at,
 * extrapolating elapsed wall-clock time while playing.
 */
function watchComputeLivePosition(array $room): float
{
    $position = (float) $room['playback_position'];

    if ($room['playback_state'] !== 'playing') {
        return $position;
    }

    $updatedAt = strtotime((string) $room['position_updated_at']);

    if ($updatedAt === false) {
        return $position;
    }

    return $position + max(0, time() - $updatedAt);
}

/**
 * Stores a chat message after validation and throttling. Body is stored raw
 * and escaped on output. Returns the new message id or an error.
 *
 * @return array{ok: bool, error?: string, id?: int}
 */
function watchAddMessage(PDO $pdo, int $roomId, int $userId, string $body): array
{
    $body = trim($body);

    if ($body === '') {
        return ['ok' => false, 'error' => 'Leere Nachricht.'];
    }

    $body = mb_substr($body, 0, watchChatMaxLength($pdo));

    // Server-side throttle against message spam.
    $recent = $pdo->prepare(
        'SELECT created_at FROM watch_messages
         WHERE room_id = :room_id AND user_id = :user_id
         ORDER BY id DESC LIMIT 1'
    );
    $recent->execute(['room_id' => $roomId, 'user_id' => $userId]);
    $last = $recent->fetchColumn();

    if ($last !== false && (time() - (int) strtotime((string) $last)) < WATCH_CHAT_MIN_INTERVAL_SECONDS) {
        return ['ok' => false, 'error' => 'Zu schnell — bitte kurz warten.'];
    }

    $statement = $pdo->prepare(
        'INSERT INTO watch_messages (room_id, user_id, body) VALUES (:room_id, :user_id, :body)'
    );
    $statement->execute(['room_id' => $roomId, 'user_id' => $userId, 'body' => $body]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * Returns chat messages with id greater than $afterId (0 = all recent),
 * capped to the newest $limit rows.
 *
 * @return array<int, array<string, mixed>>
 */
function watchGetMessages(PDO $pdo, int $roomId, int $afterId = 0, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));

    $statement = $pdo->prepare(
        'SELECT m.id, m.user_id, u.username, m.body, m.created_at
         FROM watch_messages m
         LEFT JOIN users u ON u.id = m.user_id
         WHERE m.room_id = :room_id AND m.id > :after_id
         ORDER BY m.id ASC
         LIMIT ' . $limit
    );
    $statement->execute(['room_id' => $roomId, 'after_id' => $afterId]);

    return $statement->fetchAll();
}

/**
 * Builds a JSON-serialisable authoritative snapshot of the room's playback and
 * current-video state (used by the SSE 'state' event and initial page load).
 *
 * @return array<string, mixed>
 */
function watchRoomStateJson(PDO $pdo, array $room): array
{
    return [
        'room_id' => (int) $room['id'],
        'name' => $room['name'],
        'host_id' => (int) $room['host_id'],
        'host_username' => $room['host_username'] ?? null,
        'current_item_id' => $room['current_item_id'] !== null ? (int) $room['current_item_id'] : null,
        'current_youtube_id' => $room['current_youtube_id'] ?? null,
        'current_title' => $room['current_title'] ?? null,
        'playback_state' => $room['playback_state'],
        'position' => round(watchComputeLivePosition($room), 3),
        'state_version' => (int) $room['state_version'],
        'queue_version' => (int) $room['queue_version'],
        'viewer_count' => watchOnlineCount($pdo, (int) $room['id']),
    ];
}
