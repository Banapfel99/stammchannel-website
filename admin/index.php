<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';

requireAdmin();

header('Location: /admin/users.php');
exit;