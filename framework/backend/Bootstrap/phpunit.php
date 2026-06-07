<?php

declare(strict_types=1);

use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * PHPUnit bootstrap for Hilos framework package tests.
 *
 * Loads the Composer autoloader from the monorepo root (vendor/autoload.php),
 * then populates the Hilos env accessor (Hilos::$env) from framework/tests/.env
 * (with .env.example fallback) when present. Values are read through Hilos::$env,
 * not the process environment.
 *
 * Bootstrap failures are logged and terminate with ExitCode::ERROR, matching the
 * daemon/cli/worker entry points.
 */

// A missing autoloader is an uncatchable require fatal, so guard before requiring.
// ExitCode is not loaded yet here, so use the literal error code.
$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader not found at {$autoload}. Run `composer install`.\n");
    exit(1);
}
require_once $autoload;

try {
    // framework/tests holds .env / .env.example; initEnv loads it directly.
    Hilos::initEnv(dirname(__DIR__, 2) . '/tests', copyExample: false);
} catch (Throwable $e) {
    Logger::error('Framework PHPUnit bootstrap failed: ' . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}
