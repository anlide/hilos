<?php

declare(strict_types=1);

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\CliManager;

require dirname(__DIR__) . '/vendor/autoload.php';

$manager = new CliManager([
    $argv[0],
    CliCommands::DB_FRAMEWORK_PULL,
    ...array_slice($argv, 1),
    '--projects=' . dirname(__DIR__) . '/framework-projects.json',
]);

exit($manager->answerWithoutRunning() ?? $manager->run());
