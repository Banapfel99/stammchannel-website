<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/clips.php';
require __DIR__ . '/../includes/icons.php';
require __DIR__ . '/../includes/assets.php';

requireLogin();

requireClipsSchema($pdo);

$userId = (int) $_SESSION['user_id'];
$message = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
$activeTab = ($_GET['tab'] ?? '') === 'upload' ? 'upload' : 'watch';

$maxUploadMb = getMaxClipUploadMb($pdo);
$maxDurationSeconds = getMaxClipDurationSeconds($pdo);
$knownGames = getKnownClipGameNames($pdo);

$clips = $pdo->query(
    "SELECT c.id, c.title, c.game_name, c.created_at, c.duration_seconds, c.uploader_id,
        u.username AS uploader_username,
        (SELECT COUNT(*) FROM clip_views cv WHERE cv.clip_id = c.id) AS view_count
     FROM clips c
     JOIN users u ON u.id = c.uploader_id
     WHERE c.status = 'ready'
     ORDER BY c.created_at DESC"
)->fetchAll();

$csrfToken = getCsrfToken();

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>StammClips | Stammchannel</title>

    <link
        rel="stylesheet"
        href="<?= asset('/assets/css/style.css') ?>"
    >
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

<main class="content" id="clips-page">

    <div class="page-head">
        <div class="page-head-info">
            <h1>🎬 StammClips</h1>
            <p>Private Gaming-Clips hochladen, zufällig ansehen und mit Reaktionen kommentieren.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="tab-card">

        <div class="tab-nav">
            <button type="button" class="tab-btn<?= $activeTab === 'watch' ? ' is-active' : '' ?>" data-tab="watch">
                <?= icon('film') ?> Ansehen
            </button>
            <button type="button" class="tab-btn<?= $activeTab === 'upload' ? ' is-active' : '' ?>" data-tab="upload">
                <?= icon('upload') ?> Hochladen
            </button>
        </div>

        <div class="tab-panel<?= $activeTab === 'watch' ? ' is-active' : '' ?>" data-tab-panel="watch">

            <div class="clip-random-player" id="clip-player">
                <div class="clip-video-frame">
                    <video
                        id="clip-video"
                        controls
                        preload="metadata"
                        playsinline
                    ></video>
                    <div class="clip-empty-state" id="clip-empty-state">
                        <?= icon('film') ?>
                        <p>Noch keine Clips vorhanden.</p>
                    </div>
                    <div class="clip-loading-state" id="clip-loading-state" hidden>
                        <span class="spinner"></span>
                        <p>Lade Clip …</p>
                    </div>
                    <div class="clip-error-state" id="clip-error-state" hidden>
                        <p>Clip konnte nicht geladen werden.</p>
                    </div>
                </div>

                <div class="clip-meta" id="clip-meta" hidden>
                    <h3 id="clip-title"></h3>
                    <p class="muted" id="clip-sub"></p>

                    <div class="clip-reactions" id="clip-reactions">
                        <button type="button" class="reaction-btn" data-reaction="funny">
                            <?= icon('laugh') ?> <span class="reaction-count" data-count="funny">0</span>
                        </button>
                        <button type="button" class="reaction-btn" data-reaction="nice">
                            <?= icon('heart') ?> <span class="reaction-count" data-count="nice">0</span>
                        </button>
                        <button type="button" class="reaction-btn" data-reaction="rip">
                            <?= icon('skull') ?> <span class="reaction-count" data-count="rip">0</span>
                        </button>
                    </div>

                    <div class="clip-player-actions">
                        <button type="button" class="btn-ghost" id="clip-next-btn">
                            <?= icon('dice') ?> Nächster Clip
                        </button>
                    </div>
                </div>
            </div>

            <h2 class="clip-list-heading">Alle Clips</h2>

            <?php if ($clips === []): ?>
                <p class="muted">Es wurden noch keine Clips hochgeladen. Sei der Erste!</p>
            <?php else: ?>
                <div class="clip-grid">
                    <?php foreach ($clips as $clip): ?>
                        <div class="clip-card" data-clip-id="<?= (int) $clip['id'] ?>">
                            <video
                                class="clip-card-video"
                                src="/clips/file.php?id=<?= (int) $clip['id'] ?>"
                                preload="metadata"
                                muted
                                playsinline
                            ></video>
                            <div class="clip-card-body">
                                <h4><?= htmlspecialchars($clip['title']) ?></h4>
                                <p class="muted">
                                    <?php if ($clip['game_name']): ?>
                                        <?= htmlspecialchars($clip['game_name']) ?> ·
                                    <?php endif; ?>
                                    von <?= htmlspecialchars($clip['uploader_username']) ?> ·
                                    <?= htmlspecialchars(clipRelativeTime($clip['created_at'])) ?>
                                </p>
                                <div class="clip-card-footer">
                                    <span class="badge"><?= icon('eye') ?> <?= (int) $clip['view_count'] ?></span>

                                    <?php if (isAdmin() || (int) $clip['uploader_id'] === $userId): ?>
                                        <form method="post" action="/clips/delete.php" class="inline-form" onsubmit="return confirm('Clip wirklich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="clip_id" value="<?= (int) $clip['id'] ?>">
                                            <button type="submit" class="btn-icon-ghost" title="Löschen"><?= icon('trash') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

        <div class="tab-panel<?= $activeTab === 'upload' ? ' is-active' : '' ?>" data-tab-panel="upload">

            <p class="muted">
                Erlaubte Formate: MP4, MOV, WebM, MKV, AVI · max. <?= $maxUploadMb ?> MB ·
                max. <?= $maxDurationSeconds ?> Sekunden. Clips werden automatisch zu web-optimiertem
                MP4 (H.264/AAC, max. 1080p) verarbeitet.
            </p>

            <form
                method="post"
                action="/clips/upload.php"
                enctype="multipart/form-data"
                id="clip-upload-form"
            >
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <label for="clip-title-input">Titel</label>
                <input type="text" id="clip-title-input" name="title" maxlength="150" required>

                <label for="clip-game-input">Spiel (optional)</label>
                <input type="text" id="clip-game-input" name="game_name" maxlength="100" list="clip-game-list">
                <datalist id="clip-game-list">
                    <?php foreach ($knownGames as $game): ?>
                        <option value="<?= htmlspecialchars($game) ?>">
                    <?php endforeach; ?>
                </datalist>

                <label for="clip-file-input">Videodatei</label>
                <input type="file" id="clip-file-input" name="clip" accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-msvideo" required>

                <div class="upload-progress" id="clip-upload-progress" hidden>
                    <div class="upload-progress-bar" id="clip-upload-progress-bar"></div>
                    <span class="upload-progress-label" id="clip-upload-progress-label">0%</span>
                </div>

                <p class="clip-upload-error" id="clip-upload-error" hidden></p>

                <button type="submit" id="clip-upload-submit"><?= icon('upload') ?> Clip hochladen</button>
            </form>

        </div>

    </div>

</main>

<script>
    window.CLIPS_CONFIG = {
        csrfToken: <?= json_encode($csrfToken) ?>,
        maxUploadMb: <?= json_encode($maxUploadMb) ?>,
        maxDurationSeconds: <?= json_encode($maxDurationSeconds) ?>
    };
</script>
<script src="<?= asset('/assets/js/theme-switcher.js') ?>"></script>
<script src="<?= asset('/assets/js/clips.js') ?>"></script>

</body>
</html>
