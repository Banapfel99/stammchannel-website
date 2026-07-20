<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/music.php';

requireLogin();

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
        href="/assets/css/style.css"
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

    <section class="admin-card music-player" id="music-player" data-playlist-id="<?= $playlistId ?>">

        <h2>Gemeinsam anhören</h2>

        <div class="now-playing">
            <span id="now-playing-title">Kein Titel ausgewählt</span>
        </div>

        <audio id="audio-element" preload="metadata"></audio>

        <div class="player-controls">
            <button type="button" id="btn-shuffle" title="Shuffle">🔀</button>
            <button type="button" id="btn-prev" title="Zurück">⏮</button>
            <button type="button" id="btn-play" title="Play/Pause">▶️</button>
            <button type="button" id="btn-next" title="Weiter">⏭</button>
        </div>

        <div class="player-progress">
            <input type="range" id="progress-bar" min="0" max="100" value="0" step="0.1">
        </div>

        <p class="muted" id="sync-status">Synchronisiere …</p>

    </section>

    <section class="admin-card">

        <h2>Titel</h2>

        <table class="track-table">

            <thead>
            <tr>
                <th>Cover</th>
                <th>Titel</th>
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
                            <div class="track-cover track-cover-placeholder">🎵</div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($track['title']) ?></td>
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

            <?php if ($tracks === []): ?>
                <tr><td colspan="6" class="muted">Noch keine Titel hochgeladen.</td></tr>
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

            <label>Audiodatei (mp3, m4a, aac, ogg, wav)</label>
            <input type="file" name="audio" accept="audio/*" required>

            <label>Cover (optional, jpg/png/webp)</label>
            <input type="file" name="cover" accept="image/*">

            <button type="submit">Hochladen</button>

        </form>

    </section>

    <section class="admin-card">

        <h2>Spotify kombinieren</h2>

        <p class="muted">
            Füge einen Spotify-Playlist-, Album- oder Titel-Link hinzu, um ihn
            zusammen mit den hochgeladenen Titeln in dieser Playlist anzuzeigen.
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

        <?php foreach ($spotifyLinks as $link): ?>
            <div class="spotify-embed">
                <p class="muted">
                    Hinzugefügt von <?= htmlspecialchars($link['added_by_username']) ?>

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
                            <button type="submit" class="btn-danger btn-small">Entfernen</button>
                        </form>
                    <?php endif; ?>
                </p>
                <iframe
                    src="<?= htmlspecialchars(spotifyEmbedUrl($link['spotify_type'], $link['spotify_ref_id'])) ?>"
                    width="100%"
                    height="152"
                    frameborder="0"
                    allow="encrypted-media"
                    loading="lazy"
                ></iframe>
            </div>
        <?php endforeach; ?>

    </section>

</main>

<script id="track-data" type="application/json"><?= $trackListJson ?></script>
<script>
window.MUSIC_CSRF_TOKEN = <?= json_encode(getCsrfToken(), JSON_THROW_ON_ERROR) ?>;
</script>
<script src="/assets/js/music-player.js"></script>

</body>
</html>
