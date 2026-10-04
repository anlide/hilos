<?php

declare(strict_types=1);

require_once __DIR__ . '/framework-pieces.php';

if (count($argv) !== 2 || !preg_match('/^[1-9][0-9]*$/', $argv[1])
    || (int) $argv[1] > FRAMEWORK_INTEGRATION_PIECES) {
    fwrite(STDERR, 'Usage: php scripts/framework-piece.php <1-' . FRAMEWORK_INTEGRATION_PIECES . ">\n");
    exit(2);
}

$piece = (int) $argv[1];
$classes = frameworkPieceClasses(dirname(__DIR__), $piece);
$database = frameworkPieceDatabase($piece);
fwrite(
    STDOUT,
    frameworkPieceStepId($piece) . ': database ' . $database . ', ' . count($classes)
        . ' classes — ' . implode(', ', $classes) . "\n",
);

$command = 'DB_DATABASE=' . escapeshellarg($database)
    . ' php vendor/bin/phpunit -c framework/tests/phpunit.xml --testsuite integration'
    . ' --do-not-cache-result --filter ' . escapeshellarg(frameworkPieceFilter($classes));
passthru($command, $status);
exit($status);
