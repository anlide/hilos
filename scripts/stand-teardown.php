<?php

declare(strict_types=1);

/**
 * Taking a test stand down all the way, rather than as far as `docker compose down` goes.
 *
 * `down` leaves two kinds of thing standing that the next run then trips over: a container
 * started by `docker compose run` carries the project label but is marked one-off, so `down`
 * does not count it as its own, and the project's networks outlive the containers that used
 * them. Both were found by hand on a box that was supposed to be empty, and both read later
 * as a red step rather than as leftovers — which is why the residue is asked of docker after
 * the teardown instead of assumed from its exit code.
 *
 * The knowledge of WHICH stands exist is not here: it is `scripts/test-stands.php`. This file
 * only knows how to drop one and how to tell what is left, so that a person dropping a stand
 * by hand with the stand's own command gets the same completeness a run gets.
 *
 * This file only declares functions and executes nothing, so that `scripts/down-stands.php`,
 * `scripts/run-test-suite.php` and `framework/tests/Unit/StandTeardownTest.php` can all
 * require it.
 */

/**
 * The label docker puts on every container and network of a compose project. Asking by label
 * rather than by name is what finds the one-off containers, whose names are generated.
 */
const TEARDOWN_PROJECT_LABEL = 'com.docker.compose.project';

/**
 * How long one docker command may take before it is killed. Docker answers a healthy box in
 * under a second; taking a five-node fleet down is the slow case, and a minute is past the
 * point where waiting longer tells anyone anything new.
 */
const TEARDOWN_COMMAND_TIMEOUT_SECONDS = 60;

/** How often the runner looks at a command it is waiting on. */
const TEARDOWN_POLL_INTERVAL_MICROSECONDS = 100_000;

/** The signal a command that outstayed its timeout is killed with. */
const TEARDOWN_KILL_SIGNAL = 9;

/**
 * Take every stand down, in the order the list gives them.
 *
 * The order is the list's rather than a computed one: the stands are independent, and a fixed
 * order makes the printed log comparable between runs.
 *
 * @param string $root Repository root; every stand's `cwd` is relative to it.
 * @param array<int, array{id: string, cwd: string, composeFile: string, project: string,
 *     mode: string, services: array<int, string>, containers: array<int, string>}> $stands
 * @return array<int, array{id: string, removedContainers: array<int, string>,
 *     removedNetworks: array<int, string>, residue: array{containers: array<int, string>,
 *     networks: array<int, string>}}> One record per stand, in the same order.
 */
function tearDownStands(string $root, array $stands): array
{
    $results = [];
    foreach ($stands as $stand) {
        $results[] = tearDownStand($root, $stand);
    }

    return $results;
}

/**
 * Take one stand down: the compose teardown first, then whatever docker still holds for it.
 *
 * What was removed is reported as the difference between the residue before and after, rather
 * than as what the removal commands printed: docker prints the same id twice when a container
 * is both stopped and removed, and the count in the log would then exceed what was there.
 *
 * @param string $root Repository root.
 * @param array{id: string, cwd: string, composeFile: string, project: string, mode: string,
 *     services: array<int, string>, containers: array<int, string>} $stand
 * @return array{id: string, removedContainers: array<int, string>,
 *     removedNetworks: array<int, string>, residue: array{containers: array<int, string>,
 *     networks: array<int, string>}}
 */
function tearDownStand(string $root, array $stand): array
{
    $before = standResidue($stand);
    runTeardownCommand(standDownCommand($stand), $root . '/' . $stand['cwd']);

    $held = standResidue($stand);
    if ($held['containers'] !== []) {
        runTeardownCommand(standRemoveContainersCommand($held['containers']), $root);
    }
    if ($held['networks'] !== []) {
        runTeardownCommand(standRemoveNetworksCommand($held['networks']), $root);
    }

    $after = standResidue($stand);

    return [
        'id' => $stand['id'],
        'removedContainers' => array_values(array_diff($before['containers'], $after['containers'])),
        'removedNetworks' => array_values(array_diff($before['networks'], $after['networks'])),
        'residue' => $after,
    ];
}

/**
 * What docker is holding for this stand right now.
 *
 * A `named` stand answers about its four containers only, and about no networks at all: its
 * project is shared with the owner's preview stand, and the networks there are not the test
 * stand's to remove (see the comment on the framework entry in `scripts/test-stands.php`).
 *
 * @param array{id: string, cwd: string, composeFile: string, project: string, mode: string,
 *     services: array<int, string>, containers: array<int, string>} $stand
 * @return array{containers: array<int, string>, networks: array<int, string>}
 */
function standResidue(array $stand): array
{
    $networksCommand = standResidueNetworksCommand($stand);

    return [
        'containers' => teardownNames(runTeardownCommand(standResidueContainersCommand($stand), null)),
        'networks' => $networksCommand === null ? [] : teardownNames(runTeardownCommand($networksCommand, null)),
    ];
}

/**
 * The line one stand's teardown is reported by.
 *
 * A trace, not a question: whoever finds their stand gone looks here for where it went, so the
 * line names the stand even when there was nothing to remove.
 *
 * @param array{id: string, removedContainers: array<int, string>,
 *     removedNetworks: array<int, string>, residue: array{containers: array<int, string>,
 *     networks: array<int, string>}} $result
 */
function describeTeardown(array $result): string
{
    $left = [...$result['residue']['containers'], ...$result['residue']['networks']];
    if ($left !== []) {
        return 'stands: ' . $result['id'] . ' — LEFT BEHIND: ' . implode(', ', $left);
    }
    if ($result['removedContainers'] === [] && $result['removedNetworks'] === []) {
        return 'stands: ' . $result['id'] . ' — clean';
    }

    return sprintf(
        'stands: %s — removed: %d container(s), %d network(s)',
        $result['id'],
        count($result['removedContainers']),
        count($result['removedNetworks']),
    );
}

/**
 * The compose command that drops the stand.
 *
 * `--profile "*"` is on both forms because a service behind a profile is invisible to compose
 * without it, and the cli containers of every stand here sit behind one.
 *
 * @param array{cwd: string, composeFile: string, project: string, mode: string,
 *     services: array<int, string>, containers: array<int, string>} $stand
 */
function standDownCommand(array $stand): string
{
    if ($stand['mode'] === 'named') {
        return 'docker compose -f ' . escapeshellarg($stand['composeFile']) . ' --profile "*" rm -sf '
            . implode(' ', array_map(escapeshellarg(...), $stand['services']));
    }

    return 'docker compose -f ' . escapeshellarg($stand['composeFile']) . ' --profile "*" down --remove-orphans';
}

/**
 * The command that lists the containers docker still holds for the stand.
 *
 * Names rather than ids, because the same list is both removed and printed, and an id in the
 * log answers none of the questions the person reading it has.
 *
 * @param array{project: string, mode: string, containers: array<int, string>} $stand
 */
function standResidueContainersCommand(array $stand): string
{
    if ($stand['mode'] === 'named') {
        $filters = array_map(
            static fn(string $name): string => '--filter ' . escapeshellarg('name=^' . $name . '$'),
            $stand['containers'],
        );

        return 'docker ps -a --format ' . escapeshellarg('{{.Names}}') . ' ' . implode(' ', $filters);
    }

    return 'docker ps -a --format ' . escapeshellarg('{{.Names}}') . ' --filter '
        . escapeshellarg('label=' . TEARDOWN_PROJECT_LABEL . '=' . $stand['project']);
}

/**
 * The command that lists the stand's networks, or nothing when the stand owns none.
 *
 * @param array{project: string, mode: string} $stand
 */
function standResidueNetworksCommand(array $stand): ?string
{
    if ($stand['mode'] === 'named') {
        return null;
    }

    return 'docker network ls --format ' . escapeshellarg('{{.Name}}') . ' --filter '
        . escapeshellarg('label=' . TEARDOWN_PROJECT_LABEL . '=' . $stand['project']);
}

/**
 * The command that removes containers the compose teardown left behind.
 *
 * @param array<int, string> $names
 */
function standRemoveContainersCommand(array $names): string
{
    return 'docker rm -f ' . implode(' ', array_map(escapeshellarg(...), $names));
}

/**
 * The command that removes networks the compose teardown left behind.
 *
 * @param array<int, string> $names
 */
function standRemoveNetworksCommand(array $names): string
{
    return 'docker network rm ' . implode(' ', array_map(escapeshellarg(...), $names));
}

/**
 * The names docker printed, one per line, with the blank lines of an empty answer dropped.
 *
 * @return array<int, string>
 */
function teardownNames(string $output): array
{
    $names = [];
    foreach (explode("\n", $output) as $line) {
        $name = trim($line);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * Run one docker command and return what it printed.
 *
 * A failing command is not reported here and does not stop the teardown: whether the stand is
 * actually gone is answered by asking docker again, not by an exit code — `rm` racing a
 * container that has already exited fails while doing exactly what was wanted, and a `down`
 * that fails halfway still leaves the residue lookup with something to find.
 *
 * @param string $command A shell line.
 * @param string|null $cwd Where to run it; null runs it wherever the caller stands, which is
 *     right for the lookups, since they address docker by label and not by file.
 */
function runTeardownCommand(string $command, ?string $cwd): string
{
    $outputPath = tempnam(sys_get_temp_dir(), 'hilos-teardown-');
    if ($outputPath === false) {
        return '';
    }

    $handle = proc_open($command, [1 => ['file', $outputPath, 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($handle)) {
        unlink($outputPath);

        return '';
    }

    $deadline = microtime(true) + TEARDOWN_COMMAND_TIMEOUT_SECONDS;
    while (proc_get_status($handle)['running']) {
        if (microtime(true) > $deadline) {
            proc_terminate($handle, TEARDOWN_KILL_SIGNAL);
            proc_close($handle);

            return readTeardownOutput($outputPath);
        }
        usleep(TEARDOWN_POLL_INTERVAL_MICROSECONDS);
    }
    proc_close($handle);

    return readTeardownOutput($outputPath);
}

/** Read a command's captured output and drop the file it was captured in. */
function readTeardownOutput(string $path): string
{
    $text = file_get_contents($path);
    unlink($path);

    return $text === false ? '' : $text;
}
