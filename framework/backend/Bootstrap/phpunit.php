<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Hilos framework package tests.
 *
 * Loads the Composer autoloader from the monorepo root (vendor/autoload.php).
 * Optionally loads framework/tests/.env into the process environment for DB_* and other keys.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Hilos\Hilos;

$testsRoot = dirname(__DIR__, 2) . '/tests';
$envTest = $testsRoot . '/.env';

Hilos::initEnv($testsRoot, copyExample: false);
if (file_exists($envTest)) {
    Hilos::loadEnv($envTest);
}
