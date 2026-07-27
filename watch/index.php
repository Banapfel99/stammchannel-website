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
$rooms = watchListActiveRooms($pdo);
$csrfToken = getCsrfToken();

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watch Together | Stammchannel</title>
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

        <?php if (isAdmin()): ?>
            <a href="/admin/">Admin</a>
        <?php endif; ?>

        <a href="/logout.php">Abmelden</a>

    </div>

</nav>

<main class="content" id="watch-index">

    <div class="page-head">
        <div class="page-head-info">
            <h1>🍿 Watch Together</h1>
            <p>YouTube-Videos gemeinsam und synchron ansehen.</p>
        </div>
    </div>

    <div class="tab-card">

        <form id="watch-create-form" class="watch-create-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label for="watch-create-name">Neuen Watch Room erstellen</label>
            <div class="watch-create-row">
                <input
                    type="text"
                    id="watch-create-name"
                    name="name"
                    maxlength="120"
                    placeholder="z. B. Dark Souls Lore Night"
                    required
                >
                <button type="submit" class="btn-primary"><?= icon('plus') ?> Erstellen</button>
            </div>
            <p class="watch-create-error" id="watch-create-error" hidden></p>
        </form>

        <h2 class="watch-rooms-heading">Aktive Räume</h2>

        <?php if ($rooms === []): ?>
            <div class="watch-empty">
                <?= icon('film') ?>
                <p>Momentan schaut niemand.</p>
                <p class="muted">Erstelle oben einen Raum und lade deine Freunde ein.</p>
            </div>
        <?php else: ?>
            <div class="watch-rooms">
                <?php foreach ($rooms as $room): ?>
                    <a class="watch-room-card" href="/watch/room.php?id=<?= (int) $room['id'] ?>">
                        <div class="watch-room-card-thumb">
                            <?php if (!empty($room['current_youtube_id'])): ?>
                                <img
                                    loading="lazy"
                                    src="https://i.ytimg.com/vi/<?= urlencode((string) $room['current_youtube_id']) ?>/mqdefault.jpg"
                                    alt=""
                                >
                            <?php else: ?>
                                <div class="watch-room-card-noimg"><?= icon('film') ?></div>
                            <?php endif; ?>
                            <span class="watch-room-card-viewers">
                                <?= icon('users') ?> <?= (int) $room['viewer_count'] ?>
                            </span>
                        </div>
                        <div class="watch-room-card-body">
                            <h3><?= htmlspecialchars($room['name']) ?></h3>
                            <p class="muted">
                                <?php if (!empty($room['current_title'])): ?>
                                    <?= htmlspecialchars($room['current_title']) ?>
                                <?php else: ?>
                                    Kein Video ausgewählt
                                <?php endif; ?>
                            </p>
                            <span class="badge-host">
                                <?= icon('crown') ?> <?= htmlspecialchars($room['host_username']) ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

</main>

<script src="<?= asset('/assets/js/theme-switcher.js') ?>"></script>
<script src="<?= asset('/assets/js/watch-index.js') ?>"></script>

</body>
</html>
