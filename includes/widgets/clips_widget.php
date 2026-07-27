<?php

declare(strict_types=1);

/**
 * Compact StammClips dashboard widget. Expects $pdo (PDO) to already be
 * available from the including script (see dashboard.php).
 */

$clipsReady = clipsSchemaReady($pdo);
$clipsCsrfToken = getCsrfToken();
$dashboardClip = $clipsReady ? pickRandomClip($pdo, []) : null;

?>
<div class="widget-card clip-widget-card" id="dashboard-clip-widget">

    <div class="widget-icon"><?= icon('film') ?></div>

    <h3>🎬 StammClips</h3>

    <?php if (!$clipsReady): ?>

        <p>Noch nicht eingerichtet.</p>

    <?php elseif ($dashboardClip === null): ?>

        <p>Noch keine Clips vorhanden. Sei der Erste!</p>

        <div class="widget-actions">
            <a class="btn-ghost" href="/clips/index.php?tab=upload">Clip hochladen</a>
        </div>

    <?php else: ?>

        <div class="clip-widget-video-frame">
            <video
                id="dashboard-clip-video"
                preload="metadata"
                muted
                controls
                playsinline
                src="/clips/file.php?id=<?= (int) $dashboardClip['id'] ?>"
            ></video>
        </div>

        <div class="clip-widget-reactions" id="dashboard-clip-reactions">
            <?php $reactionCounts = getClipReactionCounts($pdo, (int) $dashboardClip['id']); ?>
            <span class="reaction-chip" data-count="funny"><?= icon('laugh') ?> <?= (int) $reactionCounts['funny'] ?></span>
            <span class="reaction-chip" data-count="nice"><?= icon('heart') ?> <?= (int) $reactionCounts['nice'] ?></span>
            <span class="reaction-chip" data-count="rip"><?= icon('skull') ?> <?= (int) $reactionCounts['rip'] ?></span>
        </div>

        <p class="muted clip-widget-byline" id="dashboard-clip-byline">
            von <?= htmlspecialchars($dashboardClip['uploader_username']) ?> ·
            <?= htmlspecialchars(clipRelativeTime($dashboardClip['created_at'])) ?>
        </p>

        <div class="widget-actions">
            <button type="button" class="btn-ghost" id="dashboard-clip-next"><?= icon('dice') ?> Nächster Clip</button>
            <a class="btn-ghost" href="/clips/index.php">Öffnen</a>
        </div>

    <?php endif; ?>

</div>

<script>
    window.CLIPS_DASHBOARD_CONFIG = { csrfToken: <?= json_encode($clipsCsrfToken) ?> };
</script>
