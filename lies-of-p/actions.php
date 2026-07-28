<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/lies_of_p.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!lopSchemaReady($pdo)) {
    http_response_code(503);
    echo json_encode(['error' => 'StammRun ist noch nicht eingerichtet.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function lopJsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function lopJsonOk(array $data = []): void
{
    echo json_encode(['ok' => true] + $data);
    exit;
}

$run = lopGetActiveRun($pdo);

// Read-only actions (GET) are allowed without a CSRF token; they only expose
// data the user may already see on the page.
if ($action === 'timeline') {
    if ($run === null) {
        lopJsonOk(['entries' => []]);
    }

    $targetUserId = (int) ($_GET['user_id'] ?? $userId);
    $entries = lopBuildTimeline(lopGetTimeline($pdo, (int) $run['id'], $targetUserId));

    lopJsonOk(['entries' => $entries]);
}

if ($action === 'recommendations') {
    if ($run === null) {
        lopJsonOk(['recommendations' => []]);
    }

    $spoiler = lopGetSpoilerLevel($pdo, $userId);
    lopJsonOk(['recommendations' => lopGetRecommendations($pdo, (int) $run['id'], $userId, $spoiler)]);
}

// Everything below mutates state and requires a valid CSRF token.
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    lopJsonError('Ungültige Anfrage.', 403);
}

switch ($action) {
    case 'join':
        $run = lopGetOrCreateActiveRun($pdo, $userId);
        lopJoinRun($pdo, (int) $run['id'], $userId);
        lopJsonOk(['run_id' => (int) $run['id']]);
        break;

    case 'set_spoiler':
        $level = (int) ($_POST['level'] ?? 0);
        lopSetSpoilerLevel($pdo, $userId, $level);
        lopJsonOk(['level' => lopGetSpoilerLevel($pdo, $userId)]);
        break;

    case 'create_token':
        $name = (string) ($_POST['name'] ?? 'StammTracker');
        $created = lopCreateTrackerToken($pdo, $userId, $name, ['tracker:read', 'tracker:write']);

        // The plaintext token is returned exactly once; it is never stored.
        lopJsonOk([
            'token' => $created['token'],
            'id' => $created['id'],
            'name' => $created['name'],
        ]);
        break;

    case 'revoke_token':
        $tokenId = (int) ($_POST['token_id'] ?? 0);

        if (!lopRevokeTrackerToken($pdo, $userId, $tokenId)) {
            lopJsonError('Token konnte nicht widerrufen werden.', 404);
        }

        lopJsonOk();
        break;

    default:
        lopJsonError('Unbekannte Aktion.', 400);
}
