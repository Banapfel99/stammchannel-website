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
        <a href="/music/">Musik</a>
        <a href="/dashboard.php">Dashboard</a>
        <?php if (isAdmin()): ?><a href="/admin/">Admin</a><?php endif; ?>
        <a href="/logout.php">Abmelden</a>
    </div>

</nav>

<main class="content">

    <h1><?= htmlspecialchars($playlist['name']) ?></h1>

    <p class="muted">
        Erstellt von <?= htmlspecialchars($playlist['owner_username']) ?>
    </p>

    <?php if ($canManage): ?>
        <form method="post" action="/music/delete.php" class="inline-form" onsubmit="return confirm('Playlist inklusive aller Titel wirklich löschen?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="target" value="playlist">
            <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
            <button type="submit" class="btn-danger">Playlist löschen</button>
        </form>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <section class="music-player-hero" id="music-player" data-playlist-id="<?= $playlistId ?>">

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

        <div class="player-info">

            <div class="player-info-head">
                <h2>Gemeinsam anhören</h2>
                <span class="track-position" id="track-position"></span>
                <div class="listeners" id="listeners-list" title="Hört gerade mit"></div>
            </div>

            <div class="now-playing">
                <?= icon('headphones') ?>
                <span id="now-playing-title">Kein Titel ausgewählt</span>
                <span class="equalizer" id="equalizer">
                    <span></span><span></span><span></span><span></span>
                </span>
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
                    <span class="btn-gaming-label">Play</span>
                </button>
                <button type="button" class="btn-gaming" id="btn-next" title="Weiter">
                    <?= icon('next') ?>
                    <span class="btn-gaming-label">Weiter</span>
                </button>
            </div>

            <div class="volume-control">
                <?= icon('volume') ?>
                <input type="range" id="volume-bar" min="0" max="100" value="80" step="1">
            </div>

            <p class="muted" id="sync-status">Synchronisiere …</p>

        </div>

    </section>

    <section class="admin-card">

        <h2>Titel</h2>

        <table class="track-table">

            <thead>
            <tr>
                <th>Cover</th>
                <th>Titel</th>
                <th>Typ</th>
                <th>Hochgeladen von</th>
                <th>Wiedergaben</th>
                <th colspan="2"></th>
            </tr>
            </thead>

            <tbody>

            <?php foreach ($tracks as $track): ?>
                <tr data-track-id="<?= (int) $track['id'] ?>">
                    <td>
                        <?php if ($track['cover_filename'] !== null): ?>
                            <img
                                class="track-cover"
                                src="/music/file.php?type=cover&id=<?= (int) $track['id'] ?>"
                                alt=""
                            >
                        <?php else: ?>
                            <div class="track-cover track-cover-placeholder"><?= icon('music') ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($track['title']) ?></td>
                    <td><span class="badge badge-accent"><?= icon('upload') ?> Upload</span></td>
                    <td><?= htmlspecialchars($track['uploader_username']) ?></td>
                    <td><?= (int) $track['play_count'] ?></td>
                    <td>
                        <button
                            type="button"
                            class="btn-play-track"
                            data-track-id="<?= (int) $track['id'] ?>"
                        >
                            Abspielen
                        </button>
                    </td>
                    <td>
                        <?php if ($canManage || (int) $track['uploader_id'] === $userId): ?>
                            <form
                                method="post"
                                action="/music/delete.php"
                                class="inline-form"
                                onsubmit="return confirm('Titel wirklich löschen?');"
                            >
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                <input type="hidden" name="target" value="track">
                                <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                                <input type="hidden" name="track_id" value="<?= (int) $track['id'] ?>">
                                <button type="submit" class="btn-danger">Löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php foreach ($spotifyLinks as $link): ?>
                <tr>
                    <td>
                        <div class="track-cover track-cover-placeholder spotify-icon"><?= icon('spotify') ?></div>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars($link['spotify_url']) ?>" target="_blank" rel="noopener noreferrer">
                            Spotify-<?= htmlspecialchars(ucfirst($link['spotify_type'])) ?> öffnen
                        </a>
                        <details>
                            <summary>Vorschau</summary>
                            <iframe
                                src="<?= htmlspecialchars(spotifyEmbedUrl($link['spotify_type'], $link['spotify_ref_id'])) ?>"
                                width="100%"
                                height="152"
                                frameborder="0"
                                allow="encrypted-media"
                                loading="lazy"
                            ></iframe>
                        </details>
                    </td>
                    <td><span class="badge badge-spotify"><?= icon('spotify') ?> Spotify</span></td>
                    <td><?= htmlspecialchars($link['added_by_username']) ?></td>
                    <td>–</td>
                    <td></td>
                    <td>
                        <?php if ($canManage || (int) $link['added_by'] === $userId): ?>
                            <form
                                method="post"
                                action="/music/delete.php"
                                class="inline-form"
                                onsubmit="return confirm('Spotify-Link wirklich entfernen?');"
                            >
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                <input type="hidden" name="target" value="spotify">
                                <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                                <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                <button type="submit" class="btn-danger">Entfernen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($tracks === [] && $spotifyLinks === []): ?>
                <tr><td colspan="7" class="muted">Noch keine Titel oder Spotify-Links vorhanden.</td></tr>
            <?php endif; ?>

            </tbody>

        </table>

    </section>

    <section class="admin-card">

        <h2>Titel hochladen</h2>

        <form
            method="post"
            action="/music/upload.php"
            enctype="multipart/form-data"
        >

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

    </section>

    <section class="admin-card">

        <h2>Spotify kombinieren</h2>

        <p class="muted">
            Füge einen Spotify-Playlist-, Album- oder Titel-Link hinzu, um ihn
            zusammen mit den hochgeladenen Titeln in der Tabelle oben
            anzuzeigen.
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

    </section>

</main>

<script id="track-data" type="application/json"><?= $trackListJson ?></script>
<script>
window.MUSIC_CSRF_TOKEN = <?= json_encode(getCsrfToken(), JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= asset('/assets/js/music-player.js') ?>"></script>

</body>
</html>
