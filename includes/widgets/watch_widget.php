<?php

declare(strict_types=1);

/**
 * Compact Watch Together dashboard widget. Expects $pdo (PDO) to already be
 * available from the including script (see dashboard.php).
 */

$watchReady = watchSchemaReady($pdo);
$activeRooms = $watchReady ? watchListActiveRooms($pdo) : [];
$featuredRoom = $activeRooms[0] ?? null;

?>
<div class="widget-card watch-widget-card">

    <div class="widget-icon"><?= icon('film') ?></div>

    <h3>🍿 Watch Together</h3>

    <?php if (!$watchReady): ?>

        <p>Noch nicht eingerichtet.</p>

    <?php elseif ($featuredRoom === null): ?>

        <p class="muted">Momentan schaut niemand.</p>

        <div class="widget-actions">
            <a class="btn-ghost" href="/watch/">Watch Room erstellen</a>
        </div>

    <?php else: ?>

        <div class="watch-widget-preview">
            <?php if (!empty($featuredRoom['current_youtube_id'])): ?>
                <img
                    class="watch-widget-thumb"
                    loading="lazy"
                    src="https://i.ytimg.com/vi/<?= urlencode((string) $featuredRoom['current_youtube_id']) ?>/mqdefault.jpg"
                    alt=""
                >
            <?php else: ?>
                <div class="watch-widget-noimg"><?= icon('film') ?></div>
            <?php endif; ?>
        </div>

        <p class="watch-widget-title">
            <?= htmlspecialchars((string) ($featuredRoom['current_title'] ?? $featuredRoom['name'])) ?>
        </p>

        <p class="muted watch-widget-meta">
            <span class="badge-host"><?= icon('crown') ?> <?= htmlspecialchars($featuredRoom['host_username']) ?></span>
            <span class="badge"><?= icon('users') ?> <?= (int) $featuredRoom['viewer_count'] ?> schauen</span>
        </p>

        <div class="widget-actions">
            <a class="btn-ghost" href="/watch/room.php?id=<?= (int) $featuredRoom['id'] ?>">Raum beitreten</a>
        </div>

    <?php endif; ?>

</div>
