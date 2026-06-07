<?php

declare(strict_types=1);

use Demo\Chat\Hilos;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Utils\Logger;

/**
 * PHPUnit bootstrap for the demo chat package tests.
 *
 * Loads the Composer autoloader from demo/chat, then populates the Hilos env
 * accessor (Hilos::$env) from the project .env and overrides it with
 * demo/chat/tests/.env when present. Values are read through Hilos::$env, not the
 * process environment.
 *
 * Bootstrap failures are logged and terminate with ExitCode::ERROR, matching the
 * daemon/cli/worker entry points.
 */

$projectRoot = dirname(__DIR__, 2);

// A missing autoloader is an uncatchable require fatal, so guard before requiring.
// ExitCode is not loaded yet here, so use the literal error code.
$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader not found at {$autoload}. Run `composer install`.\n");
    exit(1);
}
require_once $autoload;

try {
    $envTest = $projectRoot . '/tests/.env';

    Hilos::initEnv($projectRoot);
    // tests/.env is an optional override; absence is fine, so keep the guard.
    if (file_exists($envTest)) {
        Hilos::loadEnv($envTest);
    }
} catch (Throwable $e) {
    Logger::error('Demo chat PHPUnit bootstrap failed: ' . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}
