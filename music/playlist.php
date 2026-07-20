<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/music.php';
require __DIR__ . '/../includes/icons.php';
require __DIR__ . '/../includes/assets.php';

requireLogin();

requireMusicSchema($pdo);

$userId = (int) $_SESSION['user_id'];
$playlistId = (int) ($_GET['id'] ?? 0);

$playlist = $playlistId > 0 ? getPlaylistOrFail($pdo, $playlistId) : null;

if ($playlist === null) {
    http_response_code(404);
    exit('Playlist nicht gefunden.');
}

ensureListenRoom($pdo, $playlistId);

$error = '';
$message = isset($_GET['msg']) ? (string) $_GET['msg'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'spotify') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ungültige Anfrage.';
    } else {
        $spotifyUrl = trim($_POST['spotify_url'] ?? '');
        $parsed = parseSpotifyUrl($spotifyUrl);

        if ($parsed === null) {
            $error = 'Bitte gib einen gültigen Spotify-Link zu Playlist, Album oder Titel ein.';
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO playlist_spotify_links
                    (playlist_id, added_by, spotify_url, spotify_type, spotify_ref_id)
                 VALUES
                    (:playlist_id, :added_by, :spotify_url, :spotify_type, :spotify_ref_id)'
            );

            $statement->execute([
                'playlist_id' => $playlistId,
                'added_by' => $userId,
                'spotify_url' => $spotifyUrl,
                'spotify_type' => $parsed['type'],
                'spotify_ref_id' => $parsed['ref_id'],
            ]);

            header('Location: /music/playlist.php?id=' . $playlistId . '&msg=' . urlencode('Spotify-Link hinzugefügt.'));
            exit;
        }
    }
}

$canManage = isAdmin() || $playlist['owner_id'] === $userId;

$tracks = $pdo->prepare(
    'SELECT t.id, t.title, t.audio_mime, t.cover_filename, t.created_at, t.uploader_id,
        u.username AS uploader_username,
        (SELECT COUNT(*) FROM track_plays tp WHERE tp.track_id = t.id) AS play_count
     FROM tracks t
     JOIN users u ON u.id = t.uploader_id
     WHERE t.playlist_id = :playlist_id
     ORDER BY t.sort_order ASC, t.created_at ASC'
);
$tracks->execute(['playlist_id' => $playlistId]);
$tracks = $tracks->fetchAll();

$spotifyLinks = $pdo->prepare(
    'SELECT sl.id, sl.spotify_url, sl.spotify_type, sl.spotify_ref_id, sl.added_by,
        u.username AS added_by_username
     FROM playlist_spotify_links sl
     JOIN users u ON u.id = sl.added_by
     WHERE sl.playlist_id = :playlist_id
     ORDER BY sl.created_at ASC'
);
$spotifyLinks->execute(['playlist_id' => $playlistId]);
$spotifyLinks = $spotifyLinks->fetchAll();

$trackListJson = json_encode(array_map(
    static fn (array $t): array => [
        'id' => (int) $t['id'],
        'title' => $t['title'],
        'uploader' => $t['uploader_username'],
        'cover' => $t['cover_filename'] !== null
            ? '/music/file.php?type=cover&id=' . (int) $t['id']
            : null,
    ],
    $tracks
), JSON_THROW_ON_ERROR);

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= htmlspecialchars($playlist['name']) ?> | Musik</title>

    <link
        rel="stylesheet"
        href="<?= asset('/assets/css/style.css') ?>"
    >
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">Stammchannel</div>

    <div class="nav-links">
        <select class="theme-switcher" title="Design wählen" aria-label="Design wählen">
            <option value="sunset">Sunset</option>
            <option value="aurora">Aurora</option>
            <option value="neon">Neon Arcade</option>
            <option value="mono">Mono</option>
        </select>
        <a href="/music/" class="is-active">Musik</a>
        <a href="/dashboard.php">Dashboard</a>
        <?php if (isAdmin()): ?><a href="/admin/">Admin</a><?php endif; ?>
        <a href="/logout.php">Abmelden</a>
    </div>

</nav>

<main class="content music-page">

    <div class="page-head">
        <div class="page-head-info">
            <h1><?= htmlspecialchars($playlist['name']) ?></h1>
            <p class="muted">Erstellt von <?= htmlspecialchars($playlist['owner_username']) ?></p>
        </div>

        <?php if ($canManage): ?>
            <div class="menu">
                <button type="button" class="btn-icon-ghost menu-toggle" title="Optionen" aria-haspopup="true" aria-expanded="false">
                    <?= icon('more') ?>
                </button>
                <div class="menu-dropdown" hidden>
                    <form method="post" action="/music/delete.php" onsubmit="return confirm('Playlist inklusive aller Titel wirklich löschen?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="target" value="playlist">
                        <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                        <button type="submit" class="menu-item menu-item-danger"><?= icon('trash') ?> Playlist löschen</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <section class="music-player-hero" id="music-player" data-playlist-id="<?= $playlistId ?>">

        <div class="soundwave-bg" aria-hidden="true">
            <?php for ($i = 0; $i < 48; $i++): ?>
                <span style="animation-duration: <?= number_format(1.6 + (($i * 37) % 100) / 60, 2) ?>s; animation-delay: -<?= number_format((($i * 53) % 100) / 40, 2) ?>s;"></span>
            <?php endfor; ?>
        </div>

        <div class="hero-grid">

            <aside class="hero-col hero-col-tracks">

                <div class="tab-nav" role="tablist">
                    <button type="button" class="tab-btn is-active" data-tab="tracks"><?= icon('music') ?> Titel</button>
                    <button type="button" class="tab-btn" data-tab="upload"><?= icon('upload') ?> Hochladen</button>
                    <button type="button" class="tab-btn" data-tab="spotify"><?= icon('spotify') ?> Spotify</button>
                </div>

                <div class="tab-panel is-active" data-tab-panel="tracks">

                    <h2 class="panel-heading">Titel (<?= count($tracks) + count($spotifyLinks) ?>)</h2>

                    <div class="track-list">

                        <?php foreach ($tracks as $track): ?>
                            <div class="track-row" data-track-id="<?= (int) $track['id'] ?>">
                                <button type="button" class="track-row-play btn-play-track" data-track-id="<?= (int) $track['id'] ?>" title="Abspielen">
                                    <?php if ($track['cover_filename'] !== null): ?>
                                        <img class="track-row-cover" src="/music/file.php?type=cover&id=<?= (int) $track['id'] ?>" alt="">
                                    <?php else: ?>
                                        <span class="track-row-cover track-row-cover-placeholder"><?= icon('music') ?></span>
                                    <?php endif; ?>
                                    <span class="track-row-play-icon"><?= icon('play') ?></span>
                                </button>
                                <div class="track-row-info">
                                    <span class="track-row-title"><?= htmlspecialchars($track['title']) ?></span>
                                    <span class="track-row-meta">Hochgeladen von <?= htmlspecialchars($track['uploader_username']) ?></span>
                                </div>
                                <div class="menu track-row-menu">
                                    <button type="button" class="btn-icon-ghost btn-icon-ghost-sm menu-toggle" title="Optionen" aria-haspopup="true" aria-expanded="false">
                                        <?= icon('more') ?>
                                    </button>
                                    <div class="menu-dropdown" hidden>
                                        <button type="button" class="menu-item btn-play-track" data-track-id="<?= (int) $track['id'] ?>"><?= icon('play') ?> Abspielen</button>
                                        <?php if ($canManage || (int) $track['uploader_id'] === $userId): ?>
                                            <form method="post" action="/music/delete.php" onsubmit="return confirm('Titel wirklich löschen?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                <input type="hidden" name="target" value="track">
                                                <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                                                <input type="hidden" name="track_id" value="<?= (int) $track['id'] ?>">
                                                <button type="submit" class="menu-item menu-item-danger"><?= icon('trash') ?> Löschen</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($spotifyLinks as $link): ?>
                            <div class="track-row">
                                <a class="track-row-play" href="<?= htmlspecialchars($link['spotify_url']) ?>" target="_blank" rel="noopener noreferrer" title="Auf Spotify öffnen">
                                    <span class="track-row-cover track-row-cover-placeholder spotify-icon"><?= icon('spotify') ?></span>
                                </a>
                                <div class="track-row-info">
                                    <a class="track-row-title" href="<?= htmlspecialchars($link['spotify_url']) ?>" target="_blank" rel="noopener noreferrer">
                                        Spotify-<?= htmlspecialchars(ucfirst($link['spotify_type'])) ?>
                                    </a>
                                    <span class="track-row-meta">Hinzugefügt von <?= htmlspecialchars($link['added_by_username']) ?></span>
                                </div>
                                <div class="menu track-row-menu">
                                    <button type="button" class="btn-icon-ghost btn-icon-ghost-sm menu-toggle" title="Optionen" aria-haspopup="true" aria-expanded="false">
                                        <?= icon('more') ?>
                                    </button>
                                    <div class="menu-dropdown" hidden>
                                        <a class="menu-item" href="<?= htmlspecialchars($link['spotify_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('link') ?> Öffnen</a>
                                        <?php if ($canManage || (int) $link['added_by'] === $userId): ?>
                                            <form method="post" action="/music/delete.php" onsubmit="return confirm('Spotify-Link wirklich entfernen?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                <input type="hidden" name="target" value="spotify">
                                                <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                                                <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                                <button type="submit" class="menu-item menu-item-danger"><?= icon('trash') ?> Entfernen</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($tracks === [] && $spotifyLinks === []): ?>
                            <p class="muted">Noch keine Titel oder Spotify-Links vorhanden.</p>
                        <?php endif; ?>

                    </div>

                </div>

                <div class="tab-panel" data-tab-panel="upload">

                    <h2 class="panel-heading">Titel hochladen</h2>

                    <form method="post" action="/music/upload.php" enctype="multipart/form-data">

                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">

                        <label>Titel</label>
                        <input type="text" name="title" maxlength="150" required>

                        <label>Audiodatei (mp3, m4a, aac, ogg, wav) — max. <?= getMaxAudioUploadMb($pdo) ?> MB</label>
                        <input type="file" name="audio" accept="audio/*" required>

                        <label>Cover (optional, jpg/png/webp) — max. <?= getMaxCoverUploadMb($pdo) ?> MB</label>
                        <input type="file" name="cover" accept="image/*">

                        <button type="submit">Hochladen</button>

                    </form>

                </div>

                <div class="tab-panel" data-tab-panel="spotify">

                    <h2 class="panel-heading">Spotify kombinieren</h2>

                    <p class="muted">
                        Füge einen Spotify-Playlist-, Album- oder Titel-Link hinzu, um ihn
                        zusammen mit den hochgeladenen Titeln in der Liste anzuzeigen.
                    </p>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="form" value="spotify">

                        <label>Spotify-Link</label>
                        <input
                            type="url"
                            name="spotify_url"
                            placeholder="https://open.spotify.com/playlist/..."
                            required
                        >

                        <button type="submit">Hinzufügen</button>
                    </form>

                </div>

            </aside>

            <div class="hero-col hero-col-player">

                <div class="vinyl-stage">
                    <div class="vinyl-arm" id="vinyl-arm"></div>
                    <div class="vinyl-record" id="vinyl-record">
                        <img id="vinyl-cover" class="vinyl-cover-img" src="" alt="" hidden>
                        <div class="vinyl-grooves"></div>
                        <div class="vinyl-label" id="vinyl-label"><?= icon('music') ?></div>
                        <div class="vinyl-spindle"></div>
                    </div>
                    <div class="vinyl-glow"></div>
                </div>

                <div class="now-playing-block">
                    <span class="track-position" id="track-position"></span>
                    <h2 class="now-playing-title" id="now-playing-title">Kein Titel ausgewählt</h2>
                    <p class="now-playing-artist" id="now-playing-artist">
                        <?= icon('headphones') ?>
                        <span id="now-playing-uploader">Wähle einen Titel aus der Liste</span>
                        <span class="equalizer" id="equalizer">
                            <span></span><span></span><span></span><span></span>
                        </span>
                    </p>
                </div>

                <audio id="audio-element" preload="metadata"></audio>

                <div class="player-progress">
                    <input type="range" id="progress-bar" min="0" max="100" value="0" step="0.1">
                    <div class="player-time">
                        <span id="time-current">0:00</span>
                        <span id="time-duration">0:00</span>
                    </div>
                </div>

                <div class="player-controls">
                    <button type="button" class="btn-gaming" id="btn-shuffle" title="Shuffle">
                        <?= icon('shuffle') ?>
                        <span class="btn-gaming-label">Shuffle</span>
                    </button>
                    <button type="button" class="btn-gaming" id="btn-prev" title="Zurück">
                        <?= icon('prev') ?>
                        <span class="btn-gaming-label">Zurück</span>
                    </button>
                    <button type="button" class="btn-gaming btn-gaming-primary" id="btn-play" title="Play/Pause">
                        <span class="icon-play"><?= icon('play') ?></span>
                        <span class="icon-pause"><?= icon('pause') ?></span>
                    </button>
                    <button type="button" class="btn-gaming" id="btn-next" title="Weiter">
                        <?= icon('next') ?>
                        <span class="btn-gaming-label">Weiter</span>
                    </button>
                    <button type="button" class="btn-gaming" id="btn-repeat" title="Wiederholen">
                        <?= icon('repeat') ?>
                        <span class="btn-gaming-label">Wiederholen</span>
                    </button>
                </div>

            </div>

            <aside class="hero-col hero-col-listen">

                <div class="listen-panel-head">
                    <h2>Gemeinsam anhören</h2>
                    <span class="live-badge"><span class="live-dot" aria-hidden="true"></span>Live</span>
                </div>

                <span class="sync-badge" id="sync-status"><?= icon('headphones') ?> <span id="sync-status-text">Synchronisiert</span></span>

                <div class="avatar-group" id="listeners-list" title="Hört gerade mit"></div>

                <div class="host-badges">
                    <span class="badge badge-owner"><?= icon('crown') ?> <?= htmlspecialchars($playlist['owner_username']) ?></span>
                    <span class="badge badge-host"><?= icon('headphones') ?> Host</span>
                </div>

                <div class="activity-feed">
                    <h3>Aktivität</h3>
                    <ul id="activity-list"></ul>
                </div>

            </aside>

        </div>

        <div class="hero-volume-bar">
            <?= icon('volume') ?>
            <input type="range" id="volume-bar" min="0" max="100" value="80" step="1">
        </div>

    </section>

</main>

<script id="track-data" type="application/json"><?= $trackListJson ?></script>
<script>
window.MUSIC_CSRF_TOKEN = <?= json_encode(getCsrfToken(), JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= asset('/assets/js/music-player.js') ?>"></script>

<script src="<?= asset('/assets/js/theme-switcher.js') ?>"></script>

</body>
</html>
