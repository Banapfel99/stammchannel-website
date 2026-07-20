<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/icons.php';

if (isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ungültige Anfrage. Bitte versuche es erneut.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, role
             FROM users
             WHERE username = :username
             LIMIT 1'
        );

        $statement->execute([
            'username' => $username,
        ]);

        $user = $statement->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header('Location: /dashboard.php');
            exit;
        }

        $error = 'Benutzername oder Passwort ist falsch.';
    }
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login | Stammchannel</title>

    <link
        rel="stylesheet"
        href="/assets/css/style.css"
    >
</head>

<body class="login-page">

<div class="login-card">

    <div class="logo">
        <?= icon('headphones', 'icon') ?>
        Stammchannel
    </div>

    <h1>
        Willkommen zurück
    </h1>

    <p class="subtitle">
        Melde dich an, um die Website zu öffnen.
    </p>

    <?php if ($error !== ''): ?>

        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <form method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(getCsrfToken()) ?>"
        >

        <label for="username">
            Benutzername
        </label>

        <input
            id="username"
            name="username"
            type="text"
            autocomplete="username"
            required
        >

        <label for="password">
            Passwort
        </label>

        <input
            id="password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            Anmelden
        </button>

    </form>

</div>

</body>
</html>