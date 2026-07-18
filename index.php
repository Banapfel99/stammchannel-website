<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
}

header('Location: /dashboard.php');
exit;