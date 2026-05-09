<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap - loads autoloader and test environment.
 *
 * Loads tests/.env if it exists (overrides .env for tests).
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Demo\Chat\Hilos;

$projectRoot = dirname(__DIR__, 2);
$envTest = $projectRoot . '/tests/.env';

Hilos::initEnv($projectRoot);
if (file_exists($envTest)) {
    Hilos::loadEnv($envTest);
}
