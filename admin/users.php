<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';

requireAdmin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Ungültige Anfrage.';
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
        href="/assets/css/style.css"
    >
</head>

<body>

<nav class="navbar">

    <div class="nav-brand">
        Stammchannel Admin
    </div>

    <div class="nav-links">

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

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </section>

</main>

</body>
</html>