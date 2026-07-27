<?php

declare(strict_types=1);

/**
 * Test runner. Discovers and executes every *_test.php file in this directory.
 * Exits non-zero if any test fails, so it can gate CI or a pre-deploy check.
 */

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    require $file;
}

$failures = [];
$passed = 0;

foreach ($GLOBALS['__tests'] as $case) {
    try {
        ($case['fn'])();
        $passed++;
        echo "\033[32m✓\033[0m " . $case['name'] . PHP_EOL;
    } catch (Throwable $e) {
        $failures[] = ['name' => $case['name'], 'error' => $e->getMessage()];
        echo "\033[31m✗\033[0m " . $case['name'] . PHP_EOL;
    }
}

echo PHP_EOL;
echo $passed . ' passed, ' . count($failures) . ' failed, '
    . (int) $GLOBALS['__assertions'] . ' assertions.' . PHP_EOL;

if ($failures !== []) {
    echo PHP_EOL . "Failures:" . PHP_EOL;

    foreach ($failures as $failure) {
        echo '  - ' . $failure['name'] . ': ' . $failure['error'] . PHP_EOL;
    }

    exit(1);
}

exit(0);
