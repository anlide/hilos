<?php

declare(strict_types=1);

use Hilos\Constants\EnvConstants;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Environment\ProjectTimeZoneStartupGuard;
use Hilos\Hilos;

/**
 * Print the effective project zone and PHP's IANA catalog for e2e authoring.
 *
 * Run in the application's container with its project Hilos facade as the only
 * argument. The environment prelude reads /app without starting persistence or
 * the daemon. Only a successful read writes JSON to stdout; refusals use stderr.
 */

if ($argc !== 2 || $argv[1] === '') {
    fwrite(STDERR, "Usage: php e2e-time-zone-env.php <project Hilos class>\n");
    exit(2);
}

try {
    $projectRoot = '/app';
    require_once $projectRoot . '/vendor/autoload.php';

    if (!is_subclass_of($argv[1], Hilos::class)) {
        fwrite(STDERR, "The argument must name a project Hilos facade class.\n");
        exit(2);
    }

    EntrypointPrelude::initEnvironment($argv[1], $projectRoot);
    ProjectTimeZoneStartupGuard::assertConfigured();

    echo json_encode([
        'projectTimeZone' => Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string(),
        'timeZones' => DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
