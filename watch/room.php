<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/watch.php';
require __DIR__ . '/../includes/icons.php';
require __DIR__ . '/../includes/assets.php';

requireLogin();

requireWatchSchema($pdo);

$userId = (int) $_SESSION['user_id'];
$roomId = (int) ($_GET['id'] ?? 0);

$room = watchGetRoom($pdo, $roomId);

if ($room === null) {
    header('Location: /watch/');
    exit;
}

// Establish presence on page load.
watchJoinRoom($pdo, $roomId, $userId);
$room = watchGetRoom($pdo, $roomId);

$isHost = (int) $room['host_id'] === $userId;
$queue = watchGetQueue($pdo, $roomId);
$messages = watchGetMessages($pdo, $roomId, 0, 50);
$lastMessageId = $messages === [] ? 0 : (int) $messages[array_key_last($messages)]['id'];
$stateSnapshot = watchRoomStateJson($pdo, $room);
$csrfToken = getCsrfToken();

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($room['name']) ?> | Watch Together</title>
    <link rel="stylesheet" href="<?= asset('/assets/css/style.css') ?>">
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">
        Stammchannel
    </div>

    <div class="nav-links">

        <select class="theme-switcher" title="Design wählen" aria-label="Design wählen">
            <option value="sunset">Sunset</option>
            <option value="aurora">Aurora</option>
            <option value="neon">Neon Arcade</option>
            <option value="mono">Mono</option>
        </select>

        <a href="/dashboard.php">Dashboard</a>
        <a href="/watch/">Watch Together</a>

        <?php if (isAdmin()): ?>
            <a href="/admin/">Admin</a>
        <?php endif; ?>

        <a href="/logout.php">Abmelden</a>

    </div>

</nav>

<main class="content watch-room" id="watch-room">

    <div class="page-head">
        <div class="page-head-info">
            <h1>🍿 <span id="watch-room-name"><?= htmlspecialchars($room['name']) ?></span></h1>
            <p class="watch-room-sub">
                <span class="badge-host" id="watch-host-badge">
                    <?= icon('crown') ?> Host: <span id="watch-host-name"><?= htmlspecialchars($room['host_username']) ?></span>
                </span>
                <span class="badge" id="watch-viewer-badge">
                    <?= icon('users') ?> <span id="watch-viewer-count"><?= (int) $stateSnapshot['viewer_count'] ?></span> schauen
                </span>
                <span class="badge badge-host-you" id="watch-you-host-badge" <?= $isHost ? '' : 'hidden' ?>>
                    Du bist Host
                </span>
            </p>
        </div>
        <div class="page-head-actions">
            <button type="button" class="btn-ghost" id="watch-leave-btn"><?= icon('logout') ?> Raum verlassen</button>
        </div>
    </div>

    <div class="watch-layout">

        <section class="watch-stage">
            <div class="watch-player-frame">
                <div id="watch-player"></div>

                <div class="watch-player-empty" id="watch-player-empty" <?= $stateSnapshot['current_youtube_id'] ? 'hidden' : '' ?>>
                    <?= icon('film') ?>
                    <p>Noch kein Video ausgewählt.</p>
                    <p class="muted">Füge unten ein YouTube-Video zur Warteschlange hinzu.</p>
                </div>

                <div class="watch-player-loading" id="watch-player-loading" hidden>
                    <span class="spinner"></span>
                    <p>Verbinde …</p>
                </div>
            </div>

            <div class="watch-now-playing" id="watch-now-playing" <?= $stateSnapshot['current_youtube_id'] ? '' : 'hidden' ?>>
                <span class="watch-now-label">Läuft gerade</span>
                <span id="watch-now-title"><?= htmlspecialchars((string) ($stateSnapshot['current_title'] ?? 'YouTube-Video')) ?></span>
            </div>

            <?php if (!$isHost): ?>
                <p class="muted watch-guest-hint">
                    Nur der Host steuert die Wiedergabe. Dein Player synchronisiert sich automatisch.
                </p>
            <?php endif; ?>
        </section>

        <aside class="watch-side">

            <div class="watch-tabs">
                <button type="button" class="tab-btn is-active" data-watch-tab="queue">
                    <?= icon('film') ?> Warteschlange
                </button>
                <button type="button" class="tab-btn" data-watch-tab="chat">
                    <?= icon('users') ?> Chat
                </button>
            </div>

            <div class="watch-panel is-active" data-watch-panel="queue">

                <form id="watch-add-form" class="watch-add-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input
                        type="url"
                        id="watch-add-url"
                        name="url"
                        placeholder="YouTube-Link einfügen …"
                        required
                    >
                    <button type="submit" class="btn-ghost" title="Video hinzufügen"><?= icon('plus') ?></button>
                </form>

                <p class="watch-add-error" id="watch-add-error" hidden></p>

                <div class="watch-queue" id="watch-queue">
                    <p class="muted watch-queue-empty" id="watch-queue-empty" <?= $queue === [] ? '' : 'hidden' ?>>
                        Die Warteschlange ist leer.
                    </p>
                </div>
            </div>

            <div class="watch-panel" data-watch-panel="chat">
                <div class="watch-chat" id="watch-chat"></div>

                <form id="watch-chat-form" class="watch-chat-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input
                        type="text"
                        id="watch-chat-input"
                        name="body"
                        maxlength="500"
                        placeholder="Nachricht …"
                    >
                    <button type="submit" class="btn-ghost" title="Senden"><?= icon('next') ?></button>
                </form>
            </div>

        </aside>

    </div>

</main>

<script>
    window.WATCH_ROOM_CONFIG = {
        roomId: <?= (int) $roomId ?>,
        userId: <?= (int) $userId ?>,
        isHost: <?= $isHost ? 'true' : 'false' ?>,
        csrfToken: <?= json_encode($csrfToken) ?>,
        state: <?= json_encode($stateSnapshot) ?>,
        queue: <?= json_encode($queue) ?>,
        messages: <?= json_encode($messages) ?>,
        lastMessageId: <?= (int) $lastMessageId ?>
    };
</script>
<script src="https://www.youtube.com/iframe_api"></script>
<script src="<?= asset('/assets/js/theme-switcher.js') ?>"></script>
<script src="<?= asset('/assets/js/watch-room.js') ?>"></script>

</body>
</html>
