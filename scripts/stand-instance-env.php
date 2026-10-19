<?php

declare(strict_types=1);

/**
 * Print the environment of one stand instance as shell exports.
 *
 * What an instance is and which names it moves is in `scripts/stand-instance.php`.
 *
 * Usage:
 *   eval "$(php scripts/stand-instance-env.php 2)"   this shell and its children run instance 2
 */

require_once __DIR__ . '/stand-instance.php';

/** The exports were printed. */
const STAND_INSTANCE_ENV_OK = 0;

/** The argument named no instance; the reason is on stderr. */
const STAND_INSTANCE_ENV_USAGE = 2;

$argument = $argv[1] ?? null;
if ($argument === null || !ctype_digit($argument)) {
    fwrite(STDERR, "usage: php scripts/stand-instance-env.php <instance number>\n");
    exit(STAND_INSTANCE_ENV_USAGE);
}

try {
    $environment = standInstanceEnvironment((int) $argument);
} catch (InvalidArgumentException $refusal) {
    fwrite(STDERR, $refusal->getMessage() . "\n");
    exit(STAND_INSTANCE_ENV_USAGE);
}

foreach ($environment as $variable => $value) {
    echo 'export ' . $variable . '=' . escapeshellarg($value) . "\n";
}

exit(STAND_INSTANCE_ENV_OK);
