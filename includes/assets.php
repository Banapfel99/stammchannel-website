<?php

declare(strict_types=1);

/**
 * Returns a static asset URL with a cache-busting "?v=" query string based on
 * the file's last modification time, so browsers/proxies fetch a fresh copy
 * whenever style.css / music-player.js etc. change after a deployment.
 */
function asset(string $path): string
{
    static $root = null;

    if ($root === null) {
        $root = dirname(__DIR__);
    }

    $fullPath = $root . $path;
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : (string) time();

    return $path . '?v=' . $version;
}
