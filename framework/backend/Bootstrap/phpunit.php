<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Hilos framework package tests.
 *
 * Loads the Composer autoloader from the monorepo root (vendor/autoload.php).
 * Optionally loads framework/tests/.env into the process environment for DB_* and other keys.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$testsRoot = dirname(__DIR__, 2) . '/tests';
$envTest = $testsRoot . '/.env';

if (file_exists($envTest)) {
    $lines = file($envTest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\"'");
                if ($name !== '') {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                }
            }
        }
    }
}
