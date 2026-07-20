<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/icons.php';
require __DIR__ . '/../includes/assets.php';

requireAdmin();

$message = '';
$error = '';
$revealedPassword = null;
$revealedForUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ungültige Anfrage.';
    } elseif (($_POST['form'] ?? '') === 'change_password') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        $targetUser = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
        $targetUser->execute(['id' => $targetUserId]);
        $targetUser = $targetUser->fetch();

        if ($targetUser === false) {
            $error = 'Benutzer wurde nicht gefunden.';
        } elseif (strlen($newPassword) < 10) {
            $error = 'Das neue Passwort muss mindestens 10 Zeichen haben.';
        } else {
            $statement = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $statement->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $targetUserId,
            ]);

            $revealedPassword = $newPassword;
            $revealedForUser = $targetUser['username'];
            $message = 'Passwort für „' . $targetUser['username'] . '" wurde geändert.';
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if (!in_array($role, ['user', 'admin'], true)) {
            $role = 'user';
        }

        if (strlen($username) < 3) {
            $error = 'Benutzername muss mindestens 3 Zeichen haben.';
        } elseif (strlen($password) < 10) {
            $error = 'Passwort muss mindestens 10 Zeichen haben.';
        } else {
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO users
                        (username, password_hash, role)
                     VALUES
                        (:username, :password_hash, :role)'
                );

                $statement->execute([
                    'username' => $username,
                    'password_hash' => password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                    'role' => $role,
                ]);

                $message = 'Benutzer wurde erstellt.';
            } catch (PDOException $e) {
                $error = 'Benutzer konnte nicht erstellt werden.';
            }
        }
    }
}

$users = $pdo
    ->query(
        'SELECT id, username, role, created_at
         FROM users
         ORDER BY created_at DESC'
    )
    ->fetchAll();

?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Benutzerverwaltung | Stammchannel
    </title>

    <link
        rel="stylesheet"
        href="<?= asset('/assets/css/style.css') ?>"
    >
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">
        Stammchannel Admin
    </div>

    <div class="nav-links">

        <select class="theme-switcher" title="Design wählen" aria-label="Design wählen">
            <option value="sunset">Sunset</option>
            <option value="aurora">Aurora</option>
            <option value="neon">Neon Arcade</option>
            <option value="mono">Mono</option>
        </select>

        <a href="/admin/settings.php">
            Einstellungen
        </a>

        <a href="/dashboard.php">
            Website
        </a>

        <a href="/logout.php">
            Abmelden
        </a>

    </div>

</nav>

<main class="admin-container">

    <h1>
        Benutzerverwaltung
    </h1>

    <?php if ($message !== ''): ?>

        <div class="success">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($revealedPassword !== null): ?>

        <div class="success password-reveal">
            <?= icon('key') ?>
            Neues Passwort für <strong><?= htmlspecialchars($revealedForUser) ?></strong>:
            <code><?= htmlspecialchars($revealedPassword) ?></code>
            <br>
            <span class="muted">
                Bitte sicher an den Benutzer weitergeben — dieses Passwort wird nirgendwo
                gespeichert und nach einem Neuladen der Seite nicht mehr angezeigt.
            </span>
        </div>

    <?php endif; ?>

    <section class="admin-card">

        <h2>
            Benutzer hinzufügen
        </h2>

        <form method="post">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(getCsrfToken()) ?>"
            >

            <label>
                Benutzername
            </label>

            <input
                type="text"
                name="username"
                required
            >

            <label>
                Passwort
            </label>

            <input
                type="password"
                name="password"
                required
            >

            <label>
                Rolle
            </label>

            <select name="role">

                <option value="user">
                    Benutzer
                </option>

                <option value="admin">
                    Administrator
                </option>

            </select>

            <button type="submit">
                Benutzer erstellen
            </button>

        </form>

    </section>

    <section class="admin-card">

        <h2>
            Benutzer
        </h2>

        <table>

            <thead>

            <tr>
                <th>Name</th>
                <th>Rolle</th>
                <th>Erstellt</th>
                <th>Passwort ändern</th>
            </tr>

            </thead>

            <tbody>

            <?php foreach ($users as $user): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($user['username']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($user['role']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($user['created_at']) ?>
                    </td>

                    <td>
                        <form method="post" class="inline-form password-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                            <input type="hidden" name="form" value="change_password">
                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <input
                                type="text"
                                name="new_password"
                                class="password-input"
                                placeholder="Neues Passwort"
                                minlength="10"
                                required
                            >
                            <button type="button" class="btn-ghost btn-small btn-generate-password" title="Zufällig generieren"><?= icon('key') ?></button>
                            <button type="submit" class="btn-small">Ändern</button>
                        </form>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </section>

</main>

<script>
document.querySelectorAll('.btn-generate-password').forEach(function (button) {
    button.addEventListener('click', function () {
        var input = button.parentElement.querySelector('.password-input');
        var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
        var bytes = new Uint32Array(16);
        window.crypto.getRandomValues(bytes);
        var value = '';
        for (var i = 0; i < bytes.length; i++) {
            value += alphabet[bytes[i] % alphabet.length];
        }
        input.value = value;
        input.type = 'text';
    });
});
</script>

<script src="<?= asset('/assets/js/theme-switcher.js') ?>"></script>

</body>
</html>