<?php
declare(strict_types=1);

/**
 * Minimal, dependency-free .env loader.
 *
 * Parses a simple KEY=VALUE file and exposes values through putenv() and
 * $_ENV/$_SERVER so getenv() can read them — no Composer required.
 * Existing real environment variables always win (so production config
 * overrides a stray .env file).
 */

function loadEnvFile(string $path = __DIR__ . '/../.env'): void {
    if (!is_file($path)) {
        return;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }
        // Strip optional surrounding quotes.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        // Real environment wins.
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}
