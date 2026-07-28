<?php

declare(strict_types=1);

/**
 * Lies of P – StammRun backend logic and the generic game-tracking foundation.
 *
 * The website never exchanges savegames. A separate local "StammTracker" client
 * pushes extracted progress/stat data to the REST API (api/tracker.php),
 * authenticated with a personal, hashed tracker token. Only data that was
 * actually synced is ever displayed — nothing is fabricated.
 *
 * The tables are deliberately game-agnostic so Dark Souls III / Elden Ring /
 * Terraria can be added later without a second architecture. Only the seed
 * catalog in database/lies_of_p_schema.sql is Lies-of-P-specific.
 */

const LOP_GAME_SLUG = 'lies-of-p';

const LOP_DEFAULT_RUN_NAME = 'StammRun';

// Valid timeline event types. Stored as raw events; the timeline is rendered
// from these, never persisted as finished text.
const LOP_EVENT_TYPES = [
    'GAME_STARTED',
    'AREA_ENTERED',
    'DEATH',
    'BOSS_ATTEMPT',
    'BOSS_DEFEATED',
    'ACHIEVEMENT_UNLOCKED',
    'ITEM_COLLECTED',
    'QUEST_COMPLETED',
    'GAME_ENDED',
];

const LOP_TRACKER_SCOPES = ['tracker:read', 'tracker:write'];

const LOP_TRACKER_DEFAULT_RATE_PER_MINUTE = 120;

const LOP_SPOILER_MIN = 0;
const LOP_SPOILER_MAX = 3;

// --------------------------------------------------------------------------
// Schema availability
// --------------------------------------------------------------------------
function lopSchemaReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->query('SELECT 1 FROM games LIMIT 1');
        $pdo->query('SELECT 1 FROM game_runs LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }

    return $ready;
}

function requireLopSchema(PDO $pdo): void
{
    if (lopSchemaReady($pdo)) {
        return;
    }

    require_once __DIR__ . '/assets.php';

    http_response_code(503);

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<title>StammRun nicht verfügbar</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(asset('/assets/css/style.css')) . '"></head><body>'
        . '<main class="content"><h1>StammRun ist noch nicht eingerichtet</h1>'
        . '<p>Die Datenbanktabellen für Lies of P fehlen. Bitte führe '
        . '<code>database/lies_of_p_schema.sql</code> einmalig gegen die Datenbank aus, '
        . 'dann lade diese Seite erneut.</p>'
        . '<p><a href="/dashboard.php">Zurück zum Dashboard</a></p>'
        . '</main></body></html>';

    exit;
}

// --------------------------------------------------------------------------
// Game lookup
// --------------------------------------------------------------------------
/**
 * @return array<string, mixed>|null
 */
function lopGame(PDO $pdo): ?array
{
    static $game = null;

    if ($game === null) {
        $statement = $pdo->prepare('SELECT * FROM games WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => LOP_GAME_SLUG]);
        $row = $statement->fetch();
        $game = $row === false ? false : $row;
    }

    return $game === false ? null : $game;
}

function lopGameId(PDO $pdo): ?int
{
    $game = lopGame($pdo);

    return $game === null ? null : (int) $game['id'];
}

// --------------------------------------------------------------------------
// Pure helpers (unit-testable without a database)
// --------------------------------------------------------------------------
function lopIsValidEventType(string $type): bool
{
    return in_array($type, LOP_EVENT_TYPES, true);
}

/**
 * Human-readable playtime, e.g. 8125 -> "2h 15m".
 */
function lopFormatPlaytime(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    if ($minutes > 0) {
        return $minutes . 'm';
    }

    return $seconds . 's';
}

/**
 * Clock-style duration for boss fight timers, e.g. 1122 -> "18:42".
 */
function lopFormatClock(int $seconds): string
{
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;

    return sprintf('%02d:%02d', $minutes, $rest);
}

function lopSpoilerLevelLabel(int $level): string
{
    switch ($level) {
        case 1:
            return 'Hint';
        case 2:
            return 'Guided';
        case 3:
            return 'Full Guide';
        case 0:
        default:
            return 'Blind';
    }
}

function lopClampSpoilerLevel(int $level): int
{
    return max(LOP_SPOILER_MIN, min(LOP_SPOILER_MAX, $level));
}

/**
 * Short German relative time, e.g. "vor 3 Minuten". Returns "nie" for null.
 */
function lopRelativeTime(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return 'nie';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return $datetime;
    }

    $diff = max(0, time() - $timestamp);

    if ($diff < 60) {
        return 'gerade eben';
    }

    if ($diff < 3600) {
        $minutes = intdiv($diff, 60);

        return 'vor ' . $minutes . ' Minute' . ($minutes === 1 ? '' : 'n');
    }

    if ($diff < 86400) {
        $hours = intdiv($diff, 3600);

        return 'vor ' . $hours . ' Stunde' . ($hours === 1 ? '' : 'n');
    }

    $days = intdiv($diff, 86400);

    return 'vor ' . $days . ' Tag' . ($days === 1 ? '' : 'en');
}

/**
 * Uppercase initials for an avatar chip, e.g. "Niklas" -> "NI".
 */
function lopAvatarInitials(string $username): string
{
    $username = trim($username);

    if ($username === '') {
        return '?';
    }

    $parts = preg_split('~[\s_\-]+~', $username) ?: [$username];

    if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }

    return mb_strtoupper(mb_substr($username, 0, 2));
}

/**
 * Deterministic hue (0..359) derived from a username, for avatar backgrounds.
 */
function lopAvatarHue(string $username): int
{
    return (int) (hexdec(substr(md5($username), 0, 6)) % 360);
}

// --------------------------------------------------------------------------
// Tracker token helpers
// --------------------------------------------------------------------------
/**
 * Token wire format: "lop_<id>.<secret>". The id enables an O(1) DB lookup;
 * only a SHA-256 hash of the secret is ever stored.
 */
function lopFormatTrackerToken(int $id, string $secret): string
{
    return 'lop_' . $id . '.' . $secret;
}

function lopHashTrackerSecret(string $secret): string
{
    return hash('sha256', $secret);
}

/**
 * @return array{id: int, secret: string}|null
 */
function lopParseTrackerToken(string $raw): ?array
{
    $raw = trim($raw);

    if (preg_match('~^lop_(\d+)\.([A-Za-z0-9_-]{16,128})$~', $raw, $matches) !== 1) {
        return null;
    }

    return ['id' => (int) $matches[1], 'secret' => $matches[2]];
}

/**
 * @param string[] $scopes
 * @return array{token: string, id: int, name: string, scopes: string}
 */
function lopCreateTrackerToken(
    PDO $pdo,
    int $userId,
    string $name = 'StammTracker',
    array $scopes = ['tracker:write'],
    ?string $expiresAt = null
): array {
    $name = trim($name);
    $name = $name === '' ? 'StammTracker' : mb_substr($name, 0, 80);

    $scopes = array_values(array_intersect(LOP_TRACKER_SCOPES, $scopes));

    if ($scopes === []) {
        $scopes = ['tracker:write'];
    }

    $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = lopHashTrackerSecret($secret);

    $statement = $pdo->prepare(
        'INSERT INTO tracker_tokens (user_id, name, token_hash, scopes, expires_at)
         VALUES (:user_id, :name, :hash, :scopes, :expires_at)'
    );
    $statement->execute([
        'user_id' => $userId,
        'name' => $name,
        'hash' => $hash,
        'scopes' => implode(' ', $scopes),
        'expires_at' => $expiresAt,
    ]);

    $id = (int) $pdo->lastInsertId();

    return [
        'token' => lopFormatTrackerToken($id, $secret),
        'id' => $id,
        'name' => $name,
        'scopes' => implode(' ', $scopes),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function lopListTrackerTokens(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, name, scopes, last_used_at, expires_at, revoked_at, created_at
         FROM tracker_tokens
         WHERE user_id = :user_id
         ORDER BY created_at DESC'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

function lopRevokeTrackerToken(PDO $pdo, int $userId, int $tokenId): bool
{
    $statement = $pdo->prepare(
        'UPDATE tracker_tokens SET revoked_at = NOW()
         WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL'
    );
    $statement->execute(['id' => $tokenId, 'user_id' => $userId]);

    return $statement->rowCount() > 0;
}

/**
 * Authenticates a raw "Authorization: Bearer <token>" value. Returns the token
 * row (including user_id and scopes) or null. Updates last_used_at on success.
 *
 * @return array<string, mixed>|null
 */
function lopAuthenticateTracker(PDO $pdo, ?string $authorizationHeader): ?array
{
    if ($authorizationHeader === null) {
        return null;
    }

    if (preg_match('~^\s*Bearer\s+(.+)$~i', $authorizationHeader, $matches) !== 1) {
        return null;
    }

    $parsed = lopParseTrackerToken($matches[1]);

    if ($parsed === null) {
        return null;
    }

    $statement = $pdo->prepare('SELECT * FROM tracker_tokens WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $parsed['id']]);
    $token = $statement->fetch();

    if ($token === false) {
        return null;
    }

    if (!hash_equals((string) $token['token_hash'], lopHashTrackerSecret($parsed['secret']))) {
        return null;
    }

    if ($token['revoked_at'] !== null) {
        return null;
    }

    if ($token['expires_at'] !== null && strtotime((string) $token['expires_at']) < time()) {
        return null;
    }

    $pdo->prepare('UPDATE tracker_tokens SET last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $token['id']]);

    return $token;
}

/**
 * @param array<string, mixed> $token
 */
function lopTokenHasScope(array $token, string $scope): bool
{
    $scopes = preg_split('~\s+~', trim((string) ($token['scopes'] ?? ''))) ?: [];

    return in_array($scope, $scopes, true);
}

/**
 * Fixed-window (per minute) rate limiter. Returns true when the request is
 * allowed, false when the limit for the current window is exhausted.
 */
function lopTrackerRateLimitAllows(PDO $pdo, int $tokenId, int $maxPerMinute): bool
{
    $maxPerMinute = max(1, $maxPerMinute);

    $pdo->prepare(
        'INSERT INTO tracker_rate_limits (token_id, window_started_at, request_count)
         VALUES (:id, NOW(), 1)
         ON DUPLICATE KEY UPDATE
            request_count = IF(window_started_at < (NOW() - INTERVAL 60 SECOND), 1, request_count + 1),
            window_started_at = IF(window_started_at < (NOW() - INTERVAL 60 SECOND), NOW(), window_started_at)'
    )->execute(['id' => $tokenId]);

    $statement = $pdo->prepare('SELECT request_count FROM tracker_rate_limits WHERE token_id = :id LIMIT 1');
    $statement->execute(['id' => $tokenId]);

    return (int) $statement->fetchColumn() <= $maxPerMinute;
}

function lopTrackerRateLimitPerMinute(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'tracker_rate_limit_per_minute', (string) LOP_TRACKER_DEFAULT_RATE_PER_MINUTE));
}

// --------------------------------------------------------------------------
// Runs and participation
// --------------------------------------------------------------------------
/**
 * @return array<string, mixed>|null
 */
function lopGetActiveRun(PDO $pdo): ?array
{
    $gameId = lopGameId($pdo);

    if ($gameId === null) {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT * FROM game_runs
         WHERE game_id = :game_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    $statement->execute(['game_id' => $gameId]);
    $run = $statement->fetch();

    return $run === false ? null : $run;
}

/**
 * Returns the current active run, creating one lazily if none exists.
 *
 * @return array<string, mixed>
 */
function lopGetOrCreateActiveRun(PDO $pdo, ?int $createdBy = null): array
{
    $run = lopGetActiveRun($pdo);

    if ($run !== null) {
        return $run;
    }

    $gameId = lopGameId($pdo);

    if ($gameId === null) {
        throw new RuntimeException('Lies of P game row missing — run the migration.');
    }

    $statement = $pdo->prepare(
        "INSERT INTO game_runs (game_id, name, status, created_by)
         VALUES (:game_id, :name, 'active', :created_by)"
    );
    $statement->execute([
        'game_id' => $gameId,
        'name' => LOP_DEFAULT_RUN_NAME,
        'created_by' => $createdBy,
    ]);

    return lopGetActiveRun($pdo) ?? [];
}

function lopIsParticipant(PDO $pdo, int $runId, int $userId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM game_run_participants WHERE run_id = :run_id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute(['run_id' => $runId, 'user_id' => $userId]);

    return $statement->fetch() !== false;
}

/**
 * Adds a user to a run and ensures they have a (zeroed) progress row so they
 * appear in the overview. No stats are fabricated — everything stays at 0 and
 * last_synced_at stays NULL until a tracker client pushes real data.
 */
function lopJoinRun(PDO $pdo, int $runId, int $userId): void
{
    $pdo->prepare(
        'INSERT IGNORE INTO game_run_participants (run_id, user_id) VALUES (:run_id, :user_id)'
    )->execute(['run_id' => $runId, 'user_id' => $userId]);

    $pdo->prepare(
        'INSERT IGNORE INTO player_progress (run_id, user_id) VALUES (:run_id, :user_id)'
    )->execute(['run_id' => $runId, 'user_id' => $userId]);
}

/**
 * Per-participant progress snapshot for the overview, widget and leaderboards.
 * `has_synced` distinguishes joined-but-not-yet-tracked players from real data.
 *
 * @return array<int, array<string, mixed>>
 */
function lopGetParticipantsProgress(PDO $pdo, int $runId): array
{
    $statement = $pdo->prepare(
        "SELECT
            p.user_id,
            u.username,
            COALESCE(pp.progress_percent, 0) AS progress_percent,
            COALESCE(pp.playtime_seconds, 0) AS playtime_seconds,
            COALESCE(pp.deaths, 0) AS deaths,
            pp.current_area_name,
            pp.last_progress_label,
            pp.last_synced_at,
            (pp.last_synced_at IS NOT NULL) AS has_synced,
            (SELECT COUNT(*) FROM boss_progress bp
               WHERE bp.run_id = p.run_id AND bp.user_id = p.user_id AND bp.status = 'defeated') AS bosses_defeated,
            (SELECT COUNT(*) FROM boss_progress bp
               WHERE bp.run_id = p.run_id AND bp.user_id = p.user_id AND bp.first_try = 1 AND bp.status = 'defeated') AS first_try_bosses
         FROM game_run_participants p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN player_progress pp ON pp.run_id = p.run_id AND pp.user_id = p.user_id
         WHERE p.run_id = :run_id
         ORDER BY progress_percent DESC, u.username ASC"
    );
    $statement->execute(['run_id' => $runId]);

    return $statement->fetchAll();
}

// --------------------------------------------------------------------------
// Progress / boss / event ingestion (used by the tracker API)
// --------------------------------------------------------------------------
/**
 * Upserts a player's high-level progress snapshot. Only provided fields are
 * updated; missing fields keep their previous value.
 *
 * @param array<string, mixed> $data
 */
function lopUpsertProgress(PDO $pdo, int $runId, int $userId, array $data): void
{
    lopJoinRun($pdo, $runId, $userId);

    $columns = [];
    $params = ['run_id' => $runId, 'user_id' => $userId];

    $map = [
        'progress_percent' => fn ($v) => max(0, min(100, (float) $v)),
        'playtime_seconds' => fn ($v) => max(0, (int) $v),
        'deaths' => fn ($v) => max(0, (int) $v),
        'current_area_id' => fn ($v) => $v === null ? null : (int) $v,
        'current_area_name' => fn ($v) => $v === null ? null : mb_substr((string) $v, 0, 120),
        'last_progress_label' => fn ($v) => $v === null ? null : mb_substr((string) $v, 0, 160),
    ];

    foreach ($map as $key => $sanitize) {
        // A null value means "not provided" — keep the previous column value
        // instead of clobbering it (partial snapshot support).
        if (array_key_exists($key, $data) && $data[$key] !== null) {
            $columns[$key] = $sanitize($data[$key]);
            $params[$key] = $columns[$key];
        }
    }

    $columns['last_synced_at'] = 'NOW()';

    // Build a targeted UPDATE clause.
    $updateParts = ['last_synced_at = NOW()'];

    foreach (array_keys($map) as $key) {
        if (array_key_exists($key, $params)) {
            $updateParts[] = $key . ' = :' . $key;
        }
    }

    $pdo->prepare(
        'UPDATE player_progress SET ' . implode(', ', $updateParts) .
        ' WHERE run_id = :run_id AND user_id = :user_id'
    )->execute($params);
}

/**
 * Records/updates a boss result for a player. Sets first_try automatically when
 * the boss is defeated with a single attempt.
 *
 * @param array<string, mixed> $data
 */
function lopUpsertBossProgress(PDO $pdo, int $runId, int $userId, int $bossId, array $data): void
{
    lopJoinRun($pdo, $runId, $userId);

    $attempts = isset($data['attempts']) ? max(0, (int) $data['attempts']) : 0;
    $deaths = isset($data['deaths']) ? max(0, (int) $data['deaths']) : 0;
    $timeSeconds = isset($data['time_seconds']) ? max(0, (int) $data['time_seconds']) : 0;
    $status = (isset($data['status']) && $data['status'] === 'defeated') ? 'defeated' : 'undefeated';
    $firstTry = ($status === 'defeated' && $attempts <= 1) ? 1 : 0;

    $statement = $pdo->prepare(
        'INSERT INTO boss_progress
            (run_id, user_id, boss_id, attempts, deaths, time_seconds, status, first_try, defeated_at)
         VALUES
            (:run_id, :user_id, :boss_id, :attempts, :deaths, :time_seconds, :status, :first_try,
             CASE WHEN :status2 = \'defeated\' THEN NOW() ELSE NULL END)
         ON DUPLICATE KEY UPDATE
            attempts = VALUES(attempts),
            deaths = VALUES(deaths),
            time_seconds = VALUES(time_seconds),
            status = VALUES(status),
            first_try = VALUES(first_try),
            defeated_at = COALESCE(defeated_at, VALUES(defeated_at))'
    );
    $statement->execute([
        'run_id' => $runId,
        'user_id' => $userId,
        'boss_id' => $bossId,
        'attempts' => $attempts,
        'deaths' => $deaths,
        'time_seconds' => $timeSeconds,
        'status' => $status,
        'status2' => $status,
        'first_try' => $firstTry,
    ]);
}

/**
 * Inserts a timeline event. Idempotent when a client_event_id is supplied
 * (duplicate deliveries are ignored). Returns the new event id, or null when a
 * duplicate was skipped.
 *
 * @param array<string, mixed> $fields
 */
function lopInsertEvent(PDO $pdo, int $runId, int $userId, string $eventType, array $fields = []): ?int
{
    if (!lopIsValidEventType($eventType)) {
        throw new InvalidArgumentException('Unbekannter Event-Typ.');
    }

    lopJoinRun($pdo, $runId, $userId);

    $meta = $fields['meta'] ?? null;
    $occurredAt = isset($fields['occurred_at']) && strtotime((string) $fields['occurred_at']) !== false
        ? date('Y-m-d H:i:s', strtotime((string) $fields['occurred_at']))
        : date('Y-m-d H:i:s');

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO game_events
            (run_id, user_id, event_type, area_id, boss_id, label, meta, client_event_id, occurred_at)
         VALUES
            (:run_id, :user_id, :event_type, :area_id, :boss_id, :label, :meta, :client_event_id, :occurred_at)'
    );
    $statement->execute([
        'run_id' => $runId,
        'user_id' => $userId,
        'event_type' => $eventType,
        'area_id' => isset($fields['area_id']) ? (int) $fields['area_id'] : null,
        'boss_id' => isset($fields['boss_id']) ? (int) $fields['boss_id'] : null,
        'label' => isset($fields['label']) ? mb_substr((string) $fields['label'], 0, 200) : null,
        'meta' => $meta === null ? null : json_encode($meta),
        'client_event_id' => isset($fields['client_event_id']) ? mb_substr((string) $fields['client_event_id'], 0, 80) : null,
        'occurred_at' => $occurredAt,
    ]);

    if ($statement->rowCount() === 0) {
        return null; // duplicate (client_event_id already stored)
    }

    return (int) $pdo->lastInsertId();
}

/**
 * Convenience: record a death (increments the aggregate death counter and adds
 * a DEATH timeline event).
 */
function lopRecordDeath(PDO $pdo, int $runId, int $userId, ?int $bossId = null, ?string $clientEventId = null): void
{
    lopJoinRun($pdo, $runId, $userId);

    $pdo->prepare(
        'UPDATE player_progress SET deaths = deaths + 1, last_synced_at = NOW()
         WHERE run_id = :run_id AND user_id = :user_id'
    )->execute(['run_id' => $runId, 'user_id' => $userId]);

    if ($bossId !== null) {
        $pdo->prepare(
            'UPDATE boss_progress SET deaths = deaths + 1
             WHERE run_id = :run_id AND user_id = :user_id AND boss_id = :boss_id'
        )->execute(['run_id' => $runId, 'user_id' => $userId, 'boss_id' => $bossId]);
    }

    lopInsertEvent($pdo, $runId, $userId, 'DEATH', [
        'boss_id' => $bossId,
        'client_event_id' => $clientEventId,
    ]);
}

// --------------------------------------------------------------------------
// Catalog, boss matrix and timeline (for the detail page)
// --------------------------------------------------------------------------
/**
 * @return array<int, array<string, mixed>>
 */
function lopGetBosses(PDO $pdo): array
{
    $gameId = lopGameId($pdo);

    if ($gameId === null) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT b.id, b.name, b.is_optional, b.sort_order, a.name AS area_name
         FROM game_bosses b
         LEFT JOIN game_areas a ON a.id = b.area_id
         WHERE b.game_id = :game_id
         ORDER BY b.sort_order ASC'
    );
    $statement->execute(['game_id' => $gameId]);

    return $statement->fetchAll();
}

/**
 * Boss × player matrix for the boss progress section. Each boss carries the
 * per-participant rows that actually have data.
 *
 * @return array<int, array<string, mixed>>
 */
function lopGetBossMatrix(PDO $pdo, int $runId): array
{
    $bosses = lopGetBosses($pdo);

    if ($bosses === []) {
        return [];
    }

    $statement = $pdo->prepare(
        "SELECT bp.boss_id, bp.user_id, u.username, bp.attempts, bp.deaths,
                bp.time_seconds, bp.status, bp.first_try
         FROM boss_progress bp
         JOIN users u ON u.id = bp.user_id
         WHERE bp.run_id = :run_id
         ORDER BY bp.status DESC, bp.time_seconds ASC"
    );
    $statement->execute(['run_id' => $runId]);

    $rowsByBoss = [];

    foreach ($statement->fetchAll() as $row) {
        $rowsByBoss[(int) $row['boss_id']][] = $row;
    }

    foreach ($bosses as &$boss) {
        $boss['players'] = $rowsByBoss[(int) $boss['id']] ?? [];
    }

    unset($boss);

    return $bosses;
}

/**
 * Raw events for a player's timeline, resolved with area/boss names. The client
 * renders the timeline from these — nothing is stored as finished text.
 *
 * @return array<int, array<string, mixed>>
 */
function lopGetTimeline(PDO $pdo, int $runId, int $userId, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));

    $statement = $pdo->prepare(
        'SELECT e.id, e.event_type, e.label, e.meta, e.occurred_at,
                a.name AS area_name, b.name AS boss_name
         FROM game_events e
         LEFT JOIN game_areas a ON a.id = e.area_id
         LEFT JOIN game_bosses b ON b.id = e.boss_id
         WHERE e.run_id = :run_id AND e.user_id = :user_id
         ORDER BY e.occurred_at ASC, e.id ASC
         LIMIT ' . $limit
    );
    $statement->execute(['run_id' => $runId, 'user_id' => $userId]);

    return $statement->fetchAll();
}

/**
 * Turns raw timeline events into presentation entries (emoji, title, detail and
 * a run-relative "MM:SS"/"HH:MM" offset from the first event). Pure function so
 * the same rendering is used on the page, in the JSON API and in tests.
 *
 * @param array<int, array<string, mixed>> $events
 * @return array<int, array<string, mixed>>
 */
function lopBuildTimeline(array $events): array
{
    if ($events === []) {
        return [];
    }

    $baseline = strtotime((string) $events[0]['occurred_at']);
    $baseline = $baseline === false ? null : $baseline;

    $glyphs = [
        'GAME_STARTED' => ['🎮', 'Run gestartet'],
        'AREA_ENTERED' => ['📍', 'Gebiet betreten'],
        'DEATH' => ['💀', 'Tod'],
        'BOSS_ATTEMPT' => ['⚔️', 'Boss-Versuch'],
        'BOSS_DEFEATED' => ['✅', 'Boss besiegt'],
        'ACHIEVEMENT_UNLOCKED' => ['🏆', 'Achievement'],
        'ITEM_COLLECTED' => ['🎁', 'Item gefunden'],
        'QUEST_COMPLETED' => ['📜', 'Quest abgeschlossen'],
        'GAME_ENDED' => ['🏁', 'Run beendet'],
    ];

    $out = [];

    foreach ($events as $event) {
        $type = (string) $event['event_type'];
        [$emoji, $defaultTitle] = $glyphs[$type] ?? ['•', $type];

        $meta = [];

        if (!empty($event['meta'])) {
            $decoded = is_array($event['meta']) ? $event['meta'] : json_decode((string) $event['meta'], true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        // Title prefers the most specific available name.
        $title = $defaultTitle;

        if ($type === 'AREA_ENTERED' && !empty($event['area_name'])) {
            $title = (string) $event['area_name'];
        } elseif (in_array($type, ['BOSS_ATTEMPT', 'BOSS_DEFEATED', 'DEATH'], true) && !empty($event['boss_name'])) {
            $title = (string) $event['boss_name'];
        } elseif (!empty($event['label'])) {
            $title = (string) $event['label'];
        }

        // Detail line built from meta hints.
        $detailParts = [];

        if (isset($meta['attempts'])) {
            $detailParts[] = (int) $meta['attempts'] . ' Attempts';
        }

        if (!empty($meta['first_try'])) {
            $detailParts[] = 'First Try 🔥';
        }

        if (isset($meta['time_seconds'])) {
            $detailParts[] = lopFormatClock((int) $meta['time_seconds']);
        }

        $occurred = strtotime((string) $event['occurred_at']);
        $offset = ($baseline !== null && $occurred !== false) ? max(0, $occurred - $baseline) : 0;

        $out[] = [
            'id' => (int) $event['id'],
            'type' => $type,
            'emoji' => $emoji,
            'title' => $title,
            'detail' => implode(' · ', $detailParts),
            'offset_label' => lopFormatClock($offset),
            'occurred_at' => (string) $event['occurred_at'],
        ];
    }

    return $out;
}

// --------------------------------------------------------------------------
// Leaderboards (computed dynamically from the snapshot data)
// --------------------------------------------------------------------------
/**
 * Builds the leaderboard sets from the participant snapshot. Only participants
 * who have actually synced data are ranked.
 *
 * @return array<int, array{key: string, icon: string, title: string, entries: array<int, array<string, mixed>>, format: string}>
 */
function lopLeaderboards(PDO $pdo, int $runId): array
{
    $players = array_values(array_filter(
        lopGetParticipantsProgress($pdo, $runId),
        static fn ($p) => (int) $p['has_synced'] === 1
    ));

    if ($players === []) {
        return [];
    }

    $withPlaytime = array_values(array_filter($players, static fn ($p) => (int) $p['playtime_seconds'] > 0));

    $sortCopy = static function (array $list, callable $cmp): array {
        usort($list, $cmp);

        return $list;
    };

    $boards = [];

    $boards[] = [
        'key' => 'progress',
        'icon' => 'crown',
        'title' => '🏆 Höchster Fortschritt',
        'format' => 'percent',
        'entries' => $sortCopy($players, static fn ($a, $b) => (float) $b['progress_percent'] <=> (float) $a['progress_percent']),
    ];

    $boards[] = [
        'key' => 'fastest',
        'icon' => 'shuffle',
        'title' => '⚡ Schnellster Progress',
        'format' => 'rate',
        'entries' => $sortCopy($withPlaytime, static function ($a, $b) {
            $rateA = (float) $a['progress_percent'] / max(1, (int) $a['playtime_seconds']);
            $rateB = (float) $b['progress_percent'] / max(1, (int) $b['playtime_seconds']);

            return $rateB <=> $rateA;
        }),
    ];

    $boards[] = [
        'key' => 'deaths',
        'icon' => 'skull',
        'title' => '💀 Meiste Tode',
        'format' => 'deaths',
        'entries' => $sortCopy($players, static fn ($a, $b) => (int) $b['deaths'] <=> (int) $a['deaths']),
    ];

    $boards[] = [
        'key' => 'survivor',
        'icon' => 'heart',
        'title' => '🛡 Wenigste Tode',
        'format' => 'deaths',
        'entries' => $sortCopy($players, static fn ($a, $b) => (int) $a['deaths'] <=> (int) $b['deaths']),
    ];

    $boards[] = [
        'key' => 'first_try',
        'icon' => 'dice',
        'title' => '🔥 Meiste First-Try-Bosse',
        'format' => 'first_try',
        'entries' => $sortCopy($players, static fn ($a, $b) => (int) $b['first_try_bosses'] <=> (int) $a['first_try_bosses']),
    ];

    return $boards;
}

// --------------------------------------------------------------------------
// Spoiler preference + recommendations
// --------------------------------------------------------------------------
function lopGetSpoilerLevel(PDO $pdo, int $userId): int
{
    $gameId = lopGameId($pdo);

    if ($gameId === null) {
        return LOP_SPOILER_MIN;
    }

    $statement = $pdo->prepare(
        'SELECT spoiler_level FROM game_user_settings WHERE user_id = :user_id AND game_id = :game_id LIMIT 1'
    );
    $statement->execute(['user_id' => $userId, 'game_id' => $gameId]);
    $value = $statement->fetchColumn();

    if ($value === false) {
        return lopClampSpoilerLevel((int) getSetting($pdo, 'lop_default_spoiler_level', (string) LOP_SPOILER_MIN));
    }

    return lopClampSpoilerLevel((int) $value);
}

function lopSetSpoilerLevel(PDO $pdo, int $userId, int $level): void
{
    $gameId = lopGameId($pdo);

    if ($gameId === null) {
        return;
    }

    $level = lopClampSpoilerLevel($level);

    $pdo->prepare(
        'INSERT INTO game_user_settings (user_id, game_id, spoiler_level)
         VALUES (:user_id, :game_id, :level)
         ON DUPLICATE KEY UPDATE spoiler_level = :level2'
    )->execute([
        'user_id' => $userId,
        'game_id' => $gameId,
        'level' => $level,
        'level2' => $level,
    ]);
}

/**
 * Spoiler-level-aware recommendations for a player: the next undefeated boss
 * plus any missable warnings for their current area, disclosed according to the
 * user's chosen spoiler level. Returns an empty list at insufficient data.
 *
 * @return array<int, array{type: string, text: string}>
 */
function lopGetRecommendations(PDO $pdo, int $runId, int $userId, int $spoilerLevel): array
{
    $gameId = lopGameId($pdo);

    if ($gameId === null) {
        return [];
    }

    $spoilerLevel = lopClampSpoilerLevel($spoilerLevel);
    $recommendations = [];

    // Next objective: the first boss the player has not yet defeated.
    $nextBoss = $pdo->prepare(
        "SELECT b.name
         FROM game_bosses b
         WHERE b.game_id = :game_id
           AND b.id NOT IN (
               SELECT bp.boss_id FROM boss_progress bp
               WHERE bp.run_id = :run_id AND bp.user_id = :user_id AND bp.status = 'defeated'
           )
         ORDER BY b.sort_order ASC LIMIT 1"
    );
    $nextBoss->execute(['game_id' => $gameId, 'run_id' => $runId, 'user_id' => $userId]);
    $bossName = $nextBoss->fetchColumn();

    if ($bossName !== false) {
        $recommendations[] = [
            'type' => 'NEXT_OBJECTIVE',
            'text' => 'Nächster Boss: ' . (string) $bossName,
        ];
    }

    // Missable warnings for the player's current area, at the chosen level.
    $area = $pdo->prepare(
        'SELECT a.spoiler_warning, a.spoiler_hint, a.spoiler_guided, a.spoiler_full, a.has_missables
         FROM player_progress pp
         JOIN game_areas a ON a.id = pp.current_area_id
         WHERE pp.run_id = :run_id AND pp.user_id = :user_id LIMIT 1'
    );
    $area->execute(['run_id' => $runId, 'user_id' => $userId]);
    $areaRow = $area->fetch();

    if ($areaRow !== false && (int) $areaRow['has_missables'] === 1) {
        $text = null;

        switch ($spoilerLevel) {
            case 3:
                $text = $areaRow['spoiler_full'] ?? $areaRow['spoiler_guided'] ?? $areaRow['spoiler_hint'] ?? $areaRow['spoiler_warning'];
                break;
            case 2:
                $text = $areaRow['spoiler_guided'] ?? $areaRow['spoiler_hint'] ?? $areaRow['spoiler_warning'];
                break;
            case 1:
                $text = $areaRow['spoiler_hint'] ?? $areaRow['spoiler_warning'];
                break;
            case 0:
            default:
                $text = $areaRow['spoiler_warning'];
                break;
        }

        if ($text !== null && $text !== '') {
            $recommendations[] = ['type' => 'MISSABLE_WARNING', 'text' => (string) $text];
        }
    }

    return $recommendations;
}
