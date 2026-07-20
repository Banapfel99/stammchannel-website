<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/music.php';

requireLogin();

requireMusicSchema($pdo);

$userId = (int) $_SESSION['user_id'];
$maxPlaylists = getMaxPlaylistsPerUser($pdo);
$currentCount = countUserPlaylists($pdo, $userId);

$error = '';
$message = isset($_GET['msg']) ? (string) $_GET['msg'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ungültige Anfrage.';
    } else {
        $name = trim($_POST['name'] ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            $error = 'Bitte gib einen gültigen Playlist-Namen ein (max. 120 Zeichen).';
        } elseif ($currentCount >= $maxPlaylists) {
            $error = 'Du hast bereits die maximale Anzahl an Playlists erreicht (' . $maxPlaylists . ').';
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO playlists (owner_id, name) VALUES (:owner_id, :name)'
            );

            $statement->execute([
                'owner_id' => $userId,
                'name' => $name,
            ]);

            header('Location: /music/playlist.php?id=' . $pdo->lastInsertId());
            exit;
        }
    }
}

$playlists = $pdo->query(
    'SELECT p.id, p.name, p.created_at, u.username AS owner_username,
        (SELECT COUNT(*) FROM tracks t WHERE t.playlist_id = p.id) AS track_count
     FROM playlists p
     JOIN users u ON u.id = p.owner_id
     ORDER BY p.created_at DESC'
)->fetchAll();

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Musik | Stammchannel</title>

    <link
        rel="stylesheet"
        href="/assets/css/style.css"
    >
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">
        Stammchannel
    </div>

    <div class="nav-links">

        <a href="/dashboard.php">
            Dashboard
        </a>

        <?php if (isAdmin()): ?>
            <a href="/admin/">Admin</a>
        <?php endif; ?>

        <a href="/logout.php">
            Abmelden
        </a>

    </div>

</nav>

<main class="content">

    <h1>Musik</h1>

    <p>
        Gemeinsame Playlists der Community. Höre zusammen mit anderen
        Mitgliedern und behalte den Überblick über Statistiken.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <section class="admin-card">

        <h2>Neue Playlist erstellen</h2>

        <p class="muted">
            Du hast <?= $currentCount ?> von <?= $maxPlaylists ?> möglichen
            Playlists erstellt.
        </p>

        <?php if ($currentCount < $maxPlaylists): ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(getCsrfToken()) ?>"
                >

                <label>Name der Playlist</label>

                <input
                    type="text"
                    name="name"
                    maxlength="120"
                    required
                >

                <button type="submit">Playlist erstellen</button>

            </form>

        <?php else: ?>

            <div class="error">
                Du hast die maximale Anzahl an Playlists erreicht.
            </div>

        <?php endif; ?>

    </section>

    <section class="widgets-grid">

        <?php foreach ($playlists as $playlist): ?>

            <a
                class="widget-card playlist-card"
                href="/music/playlist.php?id=<?= (int) $playlist['id'] ?>"
            >

                <h3><?= htmlspecialchars($playlist['name']) ?></h3>

                <p class="muted">
                    von <?= htmlspecialchars($playlist['owner_username']) ?>
                </p>

                <p><?= (int) $playlist['track_count'] ?> Titel</p>

            </a>

        <?php endforeach; ?>

        <?php if ($playlists === []): ?>
            <p class="muted">Noch keine Playlists vorhanden.</p>
        <?php endif; ?>

    </section>

</main>

</body>
</html>
