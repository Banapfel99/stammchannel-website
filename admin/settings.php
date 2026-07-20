<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';

requireAdmin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ungültige Anfrage.';
    } else {
        $maxPlaylists = (int) ($_POST['max_playlists_per_user'] ?? 0);

        if ($maxPlaylists < 1 || $maxPlaylists > 50) {
            $error = 'Bitte gib einen Wert zwischen 1 und 50 an.';
        } else {
            setSetting($pdo, 'max_playlists_per_user', (string) $maxPlaylists);
            $message = 'Einstellungen wurden gespeichert.';
        }
    }
}

$maxPlaylists = getMaxPlaylistsPerUser($pdo);

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
        href="/assets/css/style.css"
    >
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">Stammchannel Admin</div>

    <div class="nav-links">
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

            <button type="submit">Speichern</button>

        </form>

    </section>

</main>

</body>
</html>
