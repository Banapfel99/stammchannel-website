<?php

declare(strict_types=1);

/**
 * Minimal dependency-free test harness for Stammchannel.
 *
 * The project intentionally ships without Composer, so this is a tiny
 * hand-rolled runner instead of PHPUnit. Test files register cases with
 * `test(name, callable)` and assert with the helpers below. Run all tests via:
 *
 *   php tests/run.php
 *   (or: docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/run.php)
 */

/** @var array<int, array{name: string, fn: callable}> */
$GLOBALS['__tests'] = [];
$GLOBALS['__assertions'] = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

function assertTrue(bool $condition, string $message = ''): void
{
    $GLOBALS['__assertions']++;

    if ($condition !== true) {
        throw new RuntimeException($message !== '' ? $message : 'Expected true, got false.');
    }
}

function assertFalse(bool $condition, string $message = ''): void
{
    assertTrue($condition === false, $message !== '' ? $message : 'Expected false, got true.');
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assertSame($expected, $actual, string $message = ''): void
{
    $GLOBALS['__assertions']++;

    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ' — ' : '')
            . 'Expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

/**
 * @param mixed $value
 */
function assertNull($value, string $message = ''): void
{
    assertTrue($value === null, $message !== '' ? $message : 'Expected null.');
}
