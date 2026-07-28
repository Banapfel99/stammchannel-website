<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/lies_of_p.php';
require __DIR__ . '/../includes/icons.php';
require __DIR__ . '/../includes/assets.php';

requireLogin();

requireLopSchema($pdo);

$userId = (int) $_SESSION['user_id'];

// There is always a single shared, active community run; create it lazily.
$run = lopGetOrCreateActiveRun($pdo, $userId);
$runId = (int) $run['id'];

$isParticipant = lopIsParticipant($pdo, $runId, $userId);
$participants = lopGetParticipantsProgress($pdo, $runId);
$syncedParticipants = array_values(array_filter($participants, static fn ($p) => (int) $p['has_synced'] === 1));
$leaderboards = lopLeaderboards($pdo, $runId);
$bossMatrix = lopGetBossMatrix($pdo, $runId);
$spoilerLevel = lopGetSpoilerLevel($pdo, $userId);
$tokens = lopListTrackerTokens($pdo, $userId);
$recommendations = $isParticipant ? lopGetRecommendations($pdo, $runId, $userId, $spoilerLevel) : [];

// Default timeline target: the current user if they have events, else the
// furthest-progressed synced participant.
$timelineUserId = $isParticipant ? $userId : (int) ($syncedParticipants[0]['user_id'] ?? $userId);
$timeline = lopBuildTimeline(lopGetTimeline($pdo, $runId, $timelineUserId));

$csrfToken = getCsrfToken();

/**
 * Renders an initials avatar chip for a username.
 */
function lopAvatarChip(string $username): string
{
    $hue = lopAvatarHue($username);

    return '<span class="lop-avatar" style="--lop-hue: ' . $hue . '">'
        . htmlspecialchars(lopAvatarInitials($username)) . '</span>';
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lies of P – StammRun | Stammchannel</title>
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

<main class="content" id="lop-page">

    <div class="page-head">
        <div class="page-head-info">
            <h1>🎭 Lies of P – StammRun</h1>
            <p>Gemeinsamer Fortschritt, Bossversuche, Timeline und Leaderboards.</p>
        </div>
        <div class="page-head-actions">
            <?php if (!$isParticipant): ?>
                <button type="button" class="btn-primary" id="lop-join-btn"><?= icon('plus') ?> Am StammRun teilnehmen</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($recommendations !== []): ?>
        <div class="lop-reco-bar" id="lop-reco-bar">
            <?php foreach ($recommendations as $reco): ?>
                <span class="lop-reco lop-reco-<?= htmlspecialchars(strtolower($reco['type'])) ?>">
                    <?= htmlspecialchars($reco['text']) ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="tab-card">

        <div class="tab-nav">
            <button type="button" class="tab-btn is-active" data-tab="overview"><?= icon('users') ?> Übersicht</button>
            <button type="button" class="tab-btn" data-tab="leaderboards"><?= icon('crown') ?> Leaderboards</button>
            <button type="button" class="tab-btn" data-tab="bosses"><?= icon('skull') ?> Bosse</button>
            <button type="button" class="tab-btn" data-tab="timeline"><?= icon('film') ?> Timeline</button>
            <button type="button" class="tab-btn" data-tab="tracker"><?= icon('key') ?> Tracker</button>
        </div>

        <!-- ---------------------------------------------------------------- Overview -->
        <div class="tab-panel is-active" data-tab-panel="overview">

            <?php if ($participants === []): ?>
                <div class="lop-empty">
                    <?= icon('users') ?>
                    <p>Noch niemand nimmt am StammRun teil.</p>
                    <p class="muted">Tritt oben bei und verbinde später den StammTracker-Client, um deinen Fortschritt automatisch zu synchronisieren.</p>
                </div>
            <?php else: ?>
                <div class="lop-player-grid">
                    <?php foreach ($participants as $player): ?>
                        <div class="lop-player-card">
                            <div class="lop-player-head">
                                <?= lopAvatarChip((string) $player['username']) ?>
                                <div class="lop-player-ident">
                                    <span class="lop-player-name"><?= htmlspecialchars($player['username']) ?></span>
                                    <?php if ((int) $player['has_synced'] === 1): ?>
                                        <span class="lop-player-area muted">
                                            <?= $player['current_area_name'] ? htmlspecialchars($player['current_area_name']) : 'Unbekanntes Gebiet' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="lop-player-area muted">Noch nicht synchronisiert</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="lop-progress-track lop-progress-track-lg">
                                <span class="lop-progress-fill" style="width: <?= (float) $player['progress_percent'] ?>%"></span>
                            </div>

                            <div class="lop-player-stats">
                                <span><strong><?= (int) round((float) $player['progress_percent']) ?>%</strong> Fortschritt</span>
                                <span><?= icon('skull') ?> <?= (int) $player['deaths'] ?></span>
                                <span><?= htmlspecialchars(lopFormatPlaytime((int) $player['playtime_seconds'])) ?></span>
                            </div>

                            <?php if ((int) $player['has_synced'] === 1): ?>
                                <p class="muted lop-player-foot">
                                    <?= (int) $player['bosses_defeated'] ?> Bosse ·
                                    zuletzt <?= htmlspecialchars(lopRelativeTime((string) $player['last_synced_at'])) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- ------------------------------------------------------------- Leaderboards -->
        <div class="tab-panel" data-tab-panel="leaderboards">

            <?php if ($leaderboards === []): ?>
                <div class="lop-empty">
                    <?= icon('crown') ?>
                    <p>Noch keine Leaderboard-Daten.</p>
                    <p class="muted">Sobald der StammTracker Fortschritt sendet, werden die Ranglisten automatisch berechnet.</p>
                </div>
            <?php else: ?>
                <div class="lop-board-grid">
                    <?php foreach ($leaderboards as $board): ?>
                        <div class="lop-board">
                            <h3><?= htmlspecialchars($board['title']) ?></h3>
                            <ol class="lop-board-list">
                                <?php foreach (array_slice($board['entries'], 0, 5) as $rank => $entry): ?>
                                    <li>
                                        <span class="lop-board-rank">#<?= $rank + 1 ?></span>
                                        <?= lopAvatarChip((string) $entry['username']) ?>
                                        <span class="lop-board-name"><?= htmlspecialchars($entry['username']) ?></span>
                                        <span class="lop-board-value">
                                            <?php
                                            switch ($board['format']) {
                                                case 'percent':
                                                    echo (int) round((float) $entry['progress_percent']) . '%';
                                                    break;
                                                case 'deaths':
                                                    echo (int) $entry['deaths'] . ' Tode';
                                                    break;
                                                case 'first_try':
                                                    echo (int) $entry['first_try_bosses'] . '× First Try';
                                                    break;
                                                case 'rate':
                                                    $rate = (int) $entry['playtime_seconds'] > 0
                                                        ? (float) $entry['progress_percent'] / ((int) $entry['playtime_seconds'] / 3600)
                                                        : 0;
                                                    echo number_format($rate, 1, ',', '.') . ' %/h';
                                                    break;
                                                default:
                                                    echo '';
                                            }
                                            ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- -------------------------------------------------------------------- Bosses -->
        <div class="tab-panel" data-tab-panel="bosses">

            <?php if ($bossMatrix === []): ?>
                <div class="lop-empty">
                    <?= icon('skull') ?>
                    <p>Keine Boss-Daten.</p>
                </div>
            <?php else: ?>
                <div class="lop-boss-list">
                    <?php foreach ($bossMatrix as $boss): ?>
                        <div class="lop-boss">
                            <div class="lop-boss-head">
                                <h3>
                                    <?= htmlspecialchars($boss['name']) ?>
                                    <?php if ((int) $boss['is_optional'] === 1): ?>
                                        <span class="badge">optional</span>
                                    <?php endif; ?>
                                </h3>
                                <?php if (!empty($boss['area_name'])): ?>
                                    <span class="muted"><?= htmlspecialchars($boss['area_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if (empty($boss['players'])): ?>
                                <p class="muted lop-boss-empty">Noch keine Versuche getrackt.</p>
                            <?php else: ?>
                                <table class="lop-boss-table">
                                    <thead>
                                        <tr>
                                            <th>Spieler</th>
                                            <th>Attempts</th>
                                            <th>Deaths</th>
                                            <th>Zeit</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($boss['players'] as $bp): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($bp['username']) ?></td>
                                                <td><?= (int) $bp['attempts'] ?></td>
                                                <td><?= (int) $bp['deaths'] ?></td>
                                                <td><?= htmlspecialchars(lopFormatClock((int) $bp['time_seconds'])) ?></td>
                                                <td>
                                                    <?php if ($bp['status'] === 'defeated'): ?>
                                                        <span class="lop-status-ok">✅ Defeated<?= (int) $bp['first_try'] === 1 ? ' 🔥' : '' ?></span>
                                                    <?php else: ?>
                                                        <span class="lop-status-open">⏳ Offen</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- ------------------------------------------------------------------ Timeline -->
        <div class="tab-panel" data-tab-panel="timeline">

            <?php if ($syncedParticipants === []): ?>
                <div class="lop-empty">
                    <?= icon('film') ?>
                    <p>Noch keine Timeline-Events.</p>
                    <p class="muted">Die Timeline wird aus einzelnen Events generiert, sobald der StammTracker läuft.</p>
                </div>
            <?php else: ?>
                <div class="lop-timeline-switch" id="lop-timeline-switch">
                    <?php foreach ($syncedParticipants as $player): ?>
                        <button
                            type="button"
                            class="btn-ghost lop-timeline-tab<?= (int) $player['user_id'] === $timelineUserId ? ' is-active' : '' ?>"
                            data-timeline-user="<?= (int) $player['user_id'] ?>"
                        >
                            <?= htmlspecialchars($player['username']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="lop-timeline" id="lop-timeline">
                    <?php if ($timeline === []): ?>
                        <p class="muted">Für diesen Spieler wurden noch keine Events aufgezeichnet.</p>
                    <?php else: ?>
                        <?php foreach ($timeline as $entry): ?>
                            <div class="lop-timeline-entry">
                                <span class="lop-timeline-time"><?= htmlspecialchars($entry['offset_label']) ?></span>
                                <span class="lop-timeline-emoji"><?= htmlspecialchars($entry['emoji']) ?></span>
                                <span class="lop-timeline-body">
                                    <span class="lop-timeline-title"><?= htmlspecialchars($entry['title']) ?></span>
                                    <?php if ($entry['detail'] !== ''): ?>
                                        <span class="lop-timeline-detail muted"><?= htmlspecialchars($entry['detail']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- ------------------------------------------------------------------- Tracker -->
        <div class="tab-panel" data-tab-panel="tracker">

            <section class="lop-tracker-section">
                <h3>Spoiler-Level</h3>
                <p class="muted">Bestimmt, wie viele Hinweise dir zu verpassbaren Inhalten angezeigt werden.</p>

                <select id="lop-spoiler-select" class="lop-select">
                    <?php for ($level = LOP_SPOILER_MIN; $level <= LOP_SPOILER_MAX; $level++): ?>
                        <option value="<?= $level ?>" <?= $level === $spoilerLevel ? 'selected' : '' ?>>
                            <?= $level ?> – <?= htmlspecialchars(lopSpoilerLevelLabel($level)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </section>

            <section class="lop-tracker-section">
                <h3>StammTracker Tokens</h3>
                <p class="muted">
                    Persönliche API-Tokens für den lokalen StammTracker-Client. Der Client speichert
                    <strong>niemals</strong> dein Passwort – nur ein widerrufbares Token. Ein Token wird nur
                    <strong>einmal</strong> angezeigt.
                </p>

                <form id="lop-token-form" class="lop-token-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="text" id="lop-token-name" name="name" maxlength="80" placeholder="Gerätename, z. B. Gaming-PC">
                    <button type="submit" class="btn-primary"><?= icon('key') ?> Token erstellen</button>
                </form>

                <div class="lop-token-reveal" id="lop-token-reveal" hidden>
                    <p>Dein neues Token (jetzt kopieren, es wird nicht erneut angezeigt):</p>
                    <code id="lop-token-value"></code>
                    <button type="button" class="btn-ghost" id="lop-token-copy">Kopieren</button>
                </div>

                <div class="lop-token-list" id="lop-token-list">
                    <?php if ($tokens === []): ?>
                        <p class="muted" id="lop-token-empty">Noch keine Tokens erstellt.</p>
                    <?php endif; ?>

                    <?php foreach ($tokens as $token): ?>
                        <div class="lop-token-row" data-token-id="<?= (int) $token['id'] ?>">
                            <div class="lop-token-info">
                                <span class="lop-token-name"><?= htmlspecialchars($token['name']) ?></span>
                                <span class="muted">
                                    <?php if ($token['revoked_at'] !== null): ?>
                                        widerrufen
                                    <?php elseif ($token['last_used_at'] !== null): ?>
                                        zuletzt genutzt <?= htmlspecialchars(lopRelativeTime((string) $token['last_used_at'])) ?>
                                    <?php else: ?>
                                        noch nie genutzt
                                    <?php endif; ?>
                                    · <?= htmlspecialchars((string) $token['scopes']) ?>
                                </span>
                            </div>
                            <?php if ($token['revoked_at'] === null): ?>
                                <button type="button" class="btn-icon-ghost lop-token-revoke" title="Widerrufen"><?= icon('trash') ?></button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="lop-tracker-section">
                <h3>API-Endpunkt</h3>
                <p class="muted">Der StammTracker-Client sendet an:</p>
                <code class="lop-api-endpoint">POST <?= htmlspecialchars(($_SERVER['HTTP_HOST'] ?? 'stammchannel.de')) ?>/api/tracker.php</code>
                <p class="muted">Authentifizierung per <code>Authorization: Bearer &lt;token&gt;</code>.</p>
            </section>

        </div>

    </div>

</main>

<script>
    window.LOP_CONFIG = {
        csrfToken: <?= json_encode($csrfToken) ?>,
        runId: <?= $runId ?>,
        userId: <?= $userId ?>,
        isParticipant: <?= $isParticipant ? 'true' : 'false' ?>
    };
</script>
<script src="<?= asset('/assets/js/theme-switcher.js') ?>"></script>
<script src="<?= asset('/assets/js/lies-of-p.js') ?>"></script>

</body>
</html>
