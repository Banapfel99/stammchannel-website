<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/music.php';
require __DIR__ . '/../includes/icons.php';
require __DIR__ . '/../includes/assets.php';

requireAdmin();

if (!appSettingsSchemaReady($pdo)) {
    http_response_code(503);
    exit(
        'Die Musik-Datenbanktabellen fehlen. Bitte führe database/music_schema.sql '
        . 'einmalig gegen die Datenbank aus.'
    );
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ungültige Anfrage.';
    } else {
        $maxPlaylists = (int) ($_POST['max_playlists_per_user'] ?? 0);
        $maxAudioMb = (int) ($_POST['max_audio_upload_mb'] ?? 0);
        $maxCoverMb = (int) ($_POST['max_cover_upload_mb'] ?? 0);

        if ($maxPlaylists < 1 || $maxPlaylists > 50) {
            $error = 'Bitte gib eine gültige Playlist-Anzahl zwischen 1 und 50 an.';
        } elseif ($maxAudioMb < 1 || $maxAudioMb > 500) {
            $error = 'Bitte gib eine gültige Audiogröße zwischen 1 und 500 MB an.';
        } elseif ($maxCoverMb < 1 || $maxCoverMb > 50) {
            $error = 'Bitte gib eine gültige Covergröße zwischen 1 und 50 MB an.';
        } else {
            setSetting($pdo, 'max_playlists_per_user', (string) $maxPlaylists);
            setSetting($pdo, 'max_audio_upload_mb', (string) $maxAudioMb);
            setSetting($pdo, 'max_cover_upload_mb', (string) $maxCoverMb);
            $message = 'Einstellungen wurden gespeichert.';
        }
    }
}

$maxPlaylists = getMaxPlaylistsPerUser($pdo);
$maxAudioMb = getMaxAudioUploadMb($pdo);
$maxCoverMb = getMaxCoverUploadMb($pdo);

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Einstellungen | Stammchannel Admin</title>

    <link
        rel="stylesheet"
        href="<?= asset('/assets/css/style.css') ?>"
    >
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">Stammchannel Admin</div>

    <div class="nav-links">
        <select class="theme-switcher" title="Design wählen" aria-label="Design wählen">
            <option value="sunset">Sunset</option>
            <option value="aurora">Aurora</option>
            <option value="neon">Neon Arcade</option>
            <option value="mono">Mono</option>
        </select>
        <a href="/admin/users.php">Benutzer</a>
        <a href="/dashboard.php">Website</a>
        <a href="/logout.php">Abmelden</a>
    </div>

</nav>

<main class="admin-container">

    <h1>Einstellungen</h1>

    <?php if ($message !== ''): ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <section class="admin-card">

        <h2>Musik-Widget</h2>

        <form method="post">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(getCsrfToken()) ?>"
            >

            <label>Maximale Anzahl Playlists pro Benutzer</label>

            <input
                type="number"
                name="max_playlists_per_user"
                min="1"
                max="50"
                value="<?= $maxPlaylists ?>"
                required
            >

            <label>Maximale Audiodateigröße (MB)</label>

            <input
                type="number"
                name="max_audio_upload_mb"
                min="1"
                max="500"
                value="<?= $maxAudioMb ?>"
                required
            >

            <label>Maximale Cover-Bildgröße (MB)</label>

            <input
                type="number"
                name="max_cover_upload_mb"
                min="1"
                max="50"
                value="<?= $maxCoverMb ?>"
                required
            >

            <button type="submit">Speichern</button>

        </form>

        <p class="muted">
            Hinweis: Die PHP-Einstellungen <code>upload_max_filesize</code>
            und <code>post_max_size</code> auf dem Server begrenzen Uploads
            zusätzlich. Stelle sicher, dass diese mindestens so groß wie die
            oben konfigurierten Werte sind.
        </p>

    </section>

</main>

<script src="<?= asset('/assets/js/theme-switcher.js') ?>"></script>

</body>
</html>
