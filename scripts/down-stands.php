<?php

declare(strict_types=1);

/**
 * Take the test stands down — all of them, or the ones named.
 *
 * This is the command every other teardown now goes through: each demo's `test:down`, the
 * framework's `test:framework:down`, the cluster's `cluster down`, and `test:stands:down` for
 * the whole box. Putting it in the repository rather than in `hilos-ops` is deliberate: the
 * same run has to work in GitHub Actions, where `hilos-ops` does not exist.
 *
 * Nothing here decides WHETHER to tear a stand down. A stand raised by hand is taken down along
 * with the rest, without a prompt and without an exemption — a guaranteed empty box for the
 * headless line is worth more than one person's stand, because the person is at the keyboard
 * and can raise it again, while the line gets a false red at night and no way to read it. The
 * printed line is the trace that answers "where did my stand go", and it is a trace only: never
 * a question, never a reason to skip one.
 *
 * Usage:
 *   php scripts/down-stands.php                 every stand, in list order
 *   php scripts/down-stands.php chat cluster    only those
 */

/** Nothing was left behind. */
const DOWN_STANDS_OK = 0;

/** Something survived the teardown and is named on stderr. */
const DOWN_STANDS_RESIDUE = 1;

/** The command line named a stand that does not exist. */
const DOWN_STANDS_UNKNOWN_STAND = 2;

$root = dirname(__DIR__);
require_once $root . '/scripts/stand-teardown.php';
$stands = require $root . '/scripts/test-stands.php';

exit(downStands($root, $stands, array_slice($argv, 1)));

/**
 * Tear down the stands the arguments asked for, and report what is left.
 *
 * @param string $root Repository root.
 * @param array<int, array{id: string, cwd: string, composeFile: string, project: string,
 *     mode: string, services: array<int, string>, containers: array<int, string>}> $stands
 * @param array<int, string> $arguments Stand ids; none means all of them.
 */
function downStands(string $root, array $stands, array $arguments): int
{
    $selected = selectStands($stands, $arguments);
    if ($selected === null) {
        return DOWN_STANDS_UNKNOWN_STAND;
    }

    $status = DOWN_STANDS_OK;
    foreach (tearDownStands($root, $selected) as $result) {
        $line = describeTeardown($result);
        if ($result['residue']['containers'] === [] && $result['residue']['networks'] === []) {
            fwrite(STDOUT, $line . "\n");

            continue;
        }
        fwrite(STDERR, $line . "\n");
        $status = DOWN_STANDS_RESIDUE;
    }

    return $status;
}

/**
 * The stands to work on, in list order, or null when an argument named one that does not exist.
 *
 * An unknown id stops the whole command rather than being skipped: it is a typo in a script or
 * a stand that was renamed, and tearing down four of the five stands silently is the failure
 * mode this ticket exists to remove.
 *
 * @param array<int, array{id: string, cwd: string, composeFile: string, project: string,
 *     mode: string, services: array<int, string>, containers: array<int, string>}> $stands
 * @param array<int, string> $arguments
 * @return array<int, array{id: string, cwd: string, composeFile: string, project: string,
 *     mode: string, services: array<int, string>, containers: array<int, string>}>|null
 */
function selectStands(array $stands, array $arguments): ?array
{
    if ($arguments === []) {
        return $stands;
    }

    $known = array_column($stands, 'id');
    foreach ($arguments as $argument) {
        if (!in_array($argument, $known, true)) {
            fwrite(STDERR, 'stands: unknown stand ' . $argument . ' (known: ' . implode(', ', $known) . ")\n");

            return null;
        }
    }

    return array_values(array_filter(
        $stands,
        static fn(array $stand): bool => in_array($stand['id'], $arguments, true),
    ));
}
