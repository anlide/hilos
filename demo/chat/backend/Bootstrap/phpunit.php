<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — loads autoloader and test environment.
 * Loads .env.test if it exists (overrides .env for tests).
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__, 2);
$envTest = $projectRoot . '/.env.test';

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
