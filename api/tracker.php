<?php

declare(strict_types=1);

/**
 * StammTracker REST API.
 *
 * A single front controller for the local StammTracker Windows client. It is
 * intentionally token-authenticated (no website session/cookie): the client
 * never stores a username/password, only a personal, revocable Bearer token
 * whose secret is stored server-side as a SHA-256 hash.
 *
 *   Authorization: Bearer lop_<id>.<secret>
 *   Content-Type: application/json
 *
 * Actions (JSON body field "action", or ?action= in the query string):
 *   session         – identify + ensure participation, returns run/user context
 *   progress        – upsert a high-level progress snapshot           (write)
 *   event           – append a raw timeline event                     (write)
 *   boss            – upsert a boss result                            (write)
 *   death           – record a death (+ DEATH event)                  (write)
 *   recommendations – spoiler-aware next-objective/missable hints     (read)
 */

require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/lies_of_p.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function trackerJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function trackerError(string $message, int $status = 400): void
{
    trackerJson(['error' => $message], $status);
}

/**
 * Robustly reads the Authorization header across SAPIs / proxy setups.
 */
function trackerAuthorizationHeader(): ?string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                return (string) $value;
            }
        }
    }

    return null;
}

if (!lopSchemaReady($pdo)) {
    trackerError('Tracker-Backend ist nicht eingerichtet.', 503);
}

// ---- Authentication -------------------------------------------------------
$token = lopAuthenticateTracker($pdo, trackerAuthorizationHeader());

if ($token === null) {
    header('WWW-Authenticate: Bearer');
    trackerError('Ungültiges oder widerrufenes Token.', 401);
}

$tokenUserId = (int) $token['user_id'];

// ---- Rate limiting --------------------------------------------------------
if (!lopTrackerRateLimitAllows($pdo, (int) $token['id'], lopTrackerRateLimitPerMinute($pdo))) {
    header('Retry-After: 60');
    trackerError('Rate limit überschritten. Bitte kurz warten.', 429);
}

// ---- Input (JSON body preferred, form fallback) ---------------------------
$raw = file_get_contents('php://input') ?: '';
$input = [];

if ($raw !== '') {
    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        $input = $decoded;
    }
}

if ($input === [] && $_POST !== []) {
    $input = $_POST;
}

$action = (string) ($_GET['action'] ?? $input['action'] ?? '');

/**
 * @param array<string, mixed> $token
 */
function trackerRequireScope(array $token, string $scope): void
{
    if (!lopTokenHasScope($token, $scope)) {
        trackerError('Token besitzt nicht den benötigten Scope: ' . $scope, 403);
    }
}

// A single shared active run is the target for all writes.
$run = lopGetOrCreateActiveRun($pdo, $tokenUserId);
$runId = (int) $run['id'];

switch ($action) {
    case 'session':
        // Idempotent: ensure the token owner participates in the current run.
        lopJoinRun($pdo, $runId, $tokenUserId);

        trackerJson([
            'ok' => true,
            'run_id' => $runId,
            'run_name' => $run['name'],
            'user_id' => $tokenUserId,
            'scopes' => explode(' ', (string) $token['scopes']),
        ]);
        break;

    case 'progress':
        trackerRequireScope($token, 'tracker:write');

        lopUpsertProgress($pdo, $runId, $tokenUserId, [
            'progress_percent' => $input['progress_percent'] ?? null,
            'playtime_seconds' => $input['playtime_seconds'] ?? null,
            'deaths' => $input['deaths'] ?? null,
            'current_area_id' => $input['current_area_id'] ?? null,
            'current_area_name' => $input['current_area_name'] ?? null,
            'last_progress_label' => $input['last_progress_label'] ?? null,
        ]);

        trackerJson(['ok' => true]);
        break;

    case 'event':
        trackerRequireScope($token, 'tracker:write');

        $eventType = (string) ($input['event_type'] ?? '');

        if (!lopIsValidEventType($eventType)) {
            trackerError('Unbekannter Event-Typ.', 422);
        }

        try {
            $eventId = lopInsertEvent($pdo, $runId, $tokenUserId, $eventType, [
                'area_id' => $input['area_id'] ?? null,
                'boss_id' => $input['boss_id'] ?? null,
                'label' => $input['label'] ?? null,
                'meta' => $input['meta'] ?? null,
                'client_event_id' => $input['client_event_id'] ?? null,
                'occurred_at' => $input['occurred_at'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            trackerError($e->getMessage(), 422);
        }

        // A null id means the event was a duplicate (idempotent ingestion).
        trackerJson(['ok' => true, 'event_id' => $eventId, 'duplicate' => $eventId === null]);
        break;

    case 'boss':
        trackerRequireScope($token, 'tracker:write');

        $bossId = (int) ($input['boss_id'] ?? 0);

        if ($bossId <= 0) {
            trackerError('boss_id ist erforderlich.', 422);
        }

        lopUpsertBossProgress($pdo, $runId, $tokenUserId, $bossId, [
            'attempts' => $input['attempts'] ?? 0,
            'deaths' => $input['deaths'] ?? 0,
            'time_seconds' => $input['time_seconds'] ?? 0,
            'status' => $input['status'] ?? 'undefeated',
        ]);

        trackerJson(['ok' => true]);
        break;

    case 'death':
        trackerRequireScope($token, 'tracker:write');

        $bossId = isset($input['boss_id']) ? (int) $input['boss_id'] : null;
        $clientEventId = isset($input['client_event_id']) ? (string) $input['client_event_id'] : null;

        lopRecordDeath($pdo, $runId, $tokenUserId, $bossId !== null && $bossId > 0 ? $bossId : null, $clientEventId);

        trackerJson(['ok' => true]);
        break;

    case 'recommendations':
        trackerRequireScope($token, 'tracker:read');

        $spoiler = lopGetSpoilerLevel($pdo, $tokenUserId);

        trackerJson([
            'ok' => true,
            'recommendations' => lopGetRecommendations($pdo, $runId, $tokenUserId, $spoiler),
        ]);
        break;

    default:
        trackerError('Unbekannte Aktion.', 404);
}
