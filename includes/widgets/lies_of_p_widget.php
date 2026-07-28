<?php

declare(strict_types=1);

/**
 * Compact Lies of P – StammRun dashboard widget. Expects $pdo (PDO) to already
 * be available from the including script (see dashboard.php).
 *
 * Only real, synced data is shown. If nobody has tracked yet, an honest empty
 * state invites the user to open the StammRun page — no fabricated numbers.
 */

$lopReady = lopSchemaReady($pdo);
$lopRun = $lopReady ? lopGetActiveRun($pdo) : null;
$lopParticipants = $lopRun !== null ? lopGetParticipantsProgress($pdo, (int) $lopRun['id']) : [];

$lopSynced = array_values(array_filter($lopParticipants, static fn ($p) => (int) $p['has_synced'] === 1));

// Leader = highest progress; death leader = most deaths (synced players only).
$lopLeader = null;
$lopDeathLeader = null;

foreach ($lopSynced as $player) {
    if ($lopLeader === null || (float) $player['progress_percent'] > (float) $lopLeader['progress_percent']) {
        $lopLeader = $player;
    }

    if ($lopDeathLeader === null || (int) $player['deaths'] > (int) $lopDeathLeader['deaths']) {
        $lopDeathLeader = $player;
    }
}

$lopPreview = array_slice($lopSynced, 0, 4);

?>
<div class="widget-card lop-widget-card" data-href="/lies-of-p/">

    <div class="widget-icon"><?= icon('crown') ?></div>

    <h3>🎭 Lies of P – StammRun</h3>

    <?php if (!$lopReady): ?>

        <p>Noch nicht eingerichtet.</p>

    <?php elseif ($lopPreview === []): ?>

        <p class="muted">Noch kein Fortschritt getrackt. Starte deinen StammRun!</p>

        <div class="widget-actions">
            <a class="btn-ghost" href="/lies-of-p/">StammRun öffnen</a>
        </div>

    <?php else: ?>

        <div class="lop-widget-players">
            <?php foreach ($lopPreview as $player): ?>
                <div class="lop-progress-row">
                    <span class="lop-progress-name"><?= htmlspecialchars($player['username']) ?></span>
                    <span class="lop-progress-track">
                        <span class="lop-progress-fill" style="width: <?= (float) $player['progress_percent'] ?>%"></span>
                    </span>
                    <span class="lop-progress-value"><?= (int) round((float) $player['progress_percent']) ?>%</span>
                    <span class="lop-progress-deaths"><?= icon('skull') ?> <?= (int) $player['deaths'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="muted lop-widget-meta">
            <?php if ($lopLeader !== null): ?>
                <span class="badge-host"><?= icon('crown') ?> Führend: <?= htmlspecialchars($lopLeader['username']) ?></span>
            <?php endif; ?>
            <?php if ($lopDeathLeader !== null && (int) $lopDeathLeader['deaths'] > 0): ?>
                <span class="badge"><?= icon('skull') ?> Death Leader: <?= htmlspecialchars($lopDeathLeader['username']) ?></span>
            <?php endif; ?>
        </p>

        <div class="widget-actions">
            <a class="btn-ghost" href="/lies-of-p/">StammRun öffnen</a>
        </div>

    <?php endif; ?>

</div>
