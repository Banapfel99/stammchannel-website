<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/icons.php';
require __DIR__ . '/includes/assets.php';

requireLogin();
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Stammchannel</title>

    <link
        rel="stylesheet"
        href="<?= asset('/assets/css/style.css') ?>"
    >
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">
        Stammchannel
    </div>

    <div class="nav-links">

        <span>
            Hallo,
            <?= htmlspecialchars($_SESSION['username']) ?>
        </span>

        <?php if (isAdmin()): ?>

            <a href="/admin/">
                Admin
            </a>

        <?php endif; ?>

        <a href="/logout.php">
            Abmelden
        </a>

    </div>

</nav>

<main class="content">

    <h1>
        Willkommen bei Stammchannel
    </h1>

    <p>
        Du bist erfolgreich angemeldet.
    </p>

    <section class="widgets-grid">

        <a class="widget-card" href="/music/">

            <div class="widget-icon"><?= icon('music') ?></div>

            <h3>Musik</h3>

            <p>
                Gemeinsame Playlists hören, hochladen und mit Spotify
                kombinieren.
            </p>

        </a>

    </section>

    <section class="server-card">

        <h2>
            Palworld
        </h2>

        <p>
            Unser Palworld Community Server.
        </p>

        <div class="server-address">
            stammchannel.de:8211
        </div>

    </section>

</main>

</body>
</html>