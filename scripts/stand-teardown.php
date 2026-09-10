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
 * The label docker puts on a container naming the compose service it was started from. It is what
 * narrows a shared project down to one stand: a profile leaves no label on a container at all.
 */
const TEARDOWN_SERVICE_LABEL = 'com.docker.compose.service';

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
 *     mode: string, profiles: array<int, string>, networks: array<int, string>}> $stands
 * @return array<int, array{id: string, problem: string, removedContainers: array<int, string>,
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
 * The services of a `profile` stand are resolved once here and handed to all three residue
 * lookups: the answer cannot change while the teardown runs, and asking compose three times
 * would cost three subprocesses for one fact.
 *
 * A stand whose own declaration is broken is dropped and then left alone, and the problem is
 * asked for BEFORE the first residue lookup rather than at the end where the report is
 * assembled. The order carries the guard: the lookup a broken declaration produces is the
 * dangerous one — a `profile` stand naming no network asks docker for every network on the box,
 * and the removal that follows would take the owner's preview lanes with it. Deciding afterwards
 * would decide after the harm.
 *
 * @param string $root Repository root.
 * @param array{id: string, cwd: string, composeFile: string, project: string, mode: string,
 *     profiles: array<int, string>, networks: array<int, string>} $stand
 * @return array{id: string, problem: string, removedContainers: array<int, string>,
 *     removedNetworks: array<int, string>, residue: array{containers: array<int, string>,
 *     networks: array<int, string>}}
 */
function tearDownStand(string $root, array $stand): array
{
    $services = standServices($root, $stand);

    $problem = standProblem($stand, $services);
    if ($problem !== '') {
        runTeardownCommand(standDownCommand($stand), $root . '/' . $stand['cwd']);

        return [
            'id' => $stand['id'],
            'problem' => $problem,
            'removedContainers' => [],
            'removedNetworks' => [],
            'residue' => ['containers' => [], 'networks' => []],
        ];
    }

    $before = standResidue($stand, $services);
    runTeardownCommand(standDownCommand($stand), $root . '/' . $stand['cwd']);

    $held = standResidue($stand, $services);
    if ($held['containers'] !== []) {
        runTeardownCommand(standRemoveContainersCommand($held['containers']), $root);
    }
    if ($held['networks'] !== []) {
        runTeardownCommand(standRemoveNetworksCommand($held['networks']), $root);
    }

    $after = standResidue($stand, $services);

    return [
        'id' => $stand['id'],
        'problem' => $problem,
        'removedContainers' => array_values(array_diff($before['containers'], $after['containers'])),
        'removedNetworks' => array_values(array_diff($before['networks'], $after['networks'])),
        'residue' => $after,
    ];
}

/**
 * The services a `profile` stand's profiles resolve to, or null for a `project` stand, which owns
 * every service in its file and narrows nothing.
 *
 * Asked of compose by the file itself rather than kept in the registry: a service added to the
 * `test` profile then goes down with the stand instead of outliving it until somebody notices.
 *
 * @param string $root Repository root.
 * @param array{cwd: string, composeFile: string, mode: string, profiles: array<int, string>} $stand
 * @return array<int, string>|null
 */
function standServices(string $root, array $stand): ?array
{
    $command = standServicesCommand($stand);
    if ($command === null) {
        return null;
    }

    return teardownNames(runTeardownCommand($command, $root . '/' . $stand['cwd']));
}

/**
 * What is wrong with the stand's own declaration, or an empty string when nothing is.
 *
 * Two cases fill it, and both answer the one question of whether docker can be asked about this
 * stand at all — which is why they share a report line:
 *
 * A `profile` stand whose profiles match no service in its compose file: a typo in a profile
 * name, or a profile that was renamed in the file and not here. That state is indistinguishable
 * from a clean box by every other signal there is, because compose drops nothing and exits zero,
 * and each residue lookup then honestly finds nothing. The stand stays up, and the run that
 * trips over it reads its own leftovers as a red step.
 *
 * A `profile` stand that named no network: its network lookup is built as one filter per name,
 * so an empty list degenerates into `docker network ls` with no filter at all — every network on
 * the box, handed to the removal that follows. The `mode` clause is not decoration: an empty
 * `networks` is the normal, correct declaration for a `project` stand, which finds its networks
 * by the project label instead.
 *
 * @param array{composeFile: string, mode: string, profiles: array<int, string>,
 *     networks: array<int, string>} $stand
 * @param array<int, string>|null $services What the stand's profiles resolved to.
 */
function standProblem(array $stand, ?array $services): string
{
    if ($stand['mode'] === 'profile' && $stand['networks'] === []) {
        return 'profiles ' . implode(', ', $stand['profiles']) . ' declare no network in ' . $stand['composeFile'];
    }
    if ($services !== []) {
        return '';
    }

    return 'profiles ' . implode(', ', $stand['profiles']) . ' match no service in ' . $stand['composeFile'];
}

/**
 * What docker is holding for this stand right now.
 *
 * A `profile` stand answers about the containers of its own services and about the networks it
 * named: its compose project is shared with the owner's preview lane, so the project label alone
 * would hand back containers and networks that are not the test stand's to remove.
 *
 * @param array{id: string, cwd: string, composeFile: string, project: string, mode: string,
 *     profiles: array<int, string>, networks: array<int, string>} $stand
 * @param array<int, string>|null $services The stand's services; null keeps every container of
 *     the project, which is right for a stand that owns the whole of it.
 * @return array{containers: array<int, string>, networks: array<int, string>}
 */
function standResidue(array $stand, ?array $services): array
{
    return [
        'containers' => teardownContainerNames(
            runTeardownCommand(standResidueContainersCommand($stand), null),
            $services,
        ),
        'networks' => teardownNames(runTeardownCommand(standResidueNetworksCommand($stand), null)),
    ];
}

/**
 * The line one stand's teardown is reported by.
 *
 * A trace, not a question: whoever finds their stand gone looks here for where it went, so the
 * line names the stand even when there was nothing to remove.
 *
 * The problem comes first, ahead of the residue: a stand nobody could ask about reports no
 * residue at all, so the reassuring half of the line would otherwise be the only half printed.
 *
 * @param array{id: string, problem: string, removedContainers: array<int, string>,
 *     removedNetworks: array<int, string>, residue: array{containers: array<int, string>,
 *     networks: array<int, string>}} $result
 */
function describeTeardown(array $result): string
{
    if ($result['problem'] !== '') {
        return 'stands: ' . $result['id'] . ' — CANNOT ASK: ' . $result['problem'];
    }

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
 * A service behind a profile is invisible to compose without that profile named, and the cli
 * containers of every stand here sit behind one. A `project` stand asks for all of them with
 * `--profile "*"`, since the whole file is its own; a `profile` stand names its own, because the
 * star would reach the owner's preview lane out of the same file. Selected profiles are all a
 * teardown needs: a service behind an unselected profile is still IN the file, so it is not an
 * orphan and `--remove-orphans` leaves it alone.
 *
 * @param array{cwd: string, composeFile: string, project: string, mode: string,
 *     profiles: array<int, string>, networks: array<int, string>} $stand
 */
function standDownCommand(array $stand): string
{
    if ($stand['mode'] === 'profile') {
        return 'docker compose -f ' . escapeshellarg($stand['composeFile']) . ' '
            . standProfileFlags($stand['profiles']) . ' down --remove-orphans';
    }

    return 'docker compose -f ' . escapeshellarg($stand['composeFile']) . ' --profile "*" down --remove-orphans';
}

/**
 * The compose command that names the services a `profile` stand's profiles hold, or null for a
 * `project` stand, whose containers are already narrow enough at the project label.
 *
 * @param array{composeFile: string, mode: string, profiles: array<int, string>} $stand
 */
function standServicesCommand(array $stand): ?string
{
    if ($stand['mode'] !== 'profile') {
        return null;
    }

    return 'docker compose -f ' . escapeshellarg($stand['composeFile']) . ' '
        . standProfileFlags($stand['profiles']) . ' config --services';
}

/**
 * The `--profile` flags naming what a `profile` stand owns.
 *
 * One flag per profile: compose takes the option repeatedly and has no list form for it.
 *
 * @param array<int, string> $profiles
 */
function standProfileFlags(array $profiles): string
{
    return implode(' ', array_map(
        static fn(string $profile): string => '--profile ' . escapeshellarg($profile),
        $profiles,
    ));
}

/**
 * The command that lists the containers docker still holds for the stand, one per line, as a name
 * and the compose service the container belongs to.
 *
 * Names rather than ids, because the same list is both removed and printed, and an id in the
 * log answers none of the questions the person reading it has.
 *
 * One command for both modes, and the narrowing left to php: docker joins two `--filter label=`
 * by AND, so a list of services cannot be asked for as a filter at all. The alternative, asking
 * compose for its own containers, cannot be narrowed by profile either — `compose ps` ignores
 * `--profile` — and a container carries no profile label to filter on.
 *
 * @param array{project: string, mode: string} $stand
 */
function standResidueContainersCommand(array $stand): string
{
    return 'docker ps -a --format ' . escapeshellarg('{{.Names}}\t{{.Label "' . TEARDOWN_SERVICE_LABEL . '"}}')
        . ' --filter ' . escapeshellarg('label=' . TEARDOWN_PROJECT_LABEL . '=' . $stand['project']);
}

/**
 * The command that lists the networks docker still holds for the stand.
 *
 * A `profile` stand is asked by the names it declared, anchored so that no longer name matches:
 * its project label is the preview lane's too, and the lane's default network answers to it. The
 * names go into one call because docker joins several `name=` filters by OR.
 *
 * @param array{project: string, mode: string, networks: array<int, string>} $stand
 */
function standResidueNetworksCommand(array $stand): string
{
    if ($stand['mode'] === 'profile') {
        $filters = array_map(
            static fn(string $name): string => '--filter ' . escapeshellarg('name=^' . $name . '$'),
            $stand['networks'],
        );

        return 'docker network ls --format ' . escapeshellarg('{{.Name}}') . ' ' . implode(' ', $filters);
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
 * The container names docker printed, narrowed to the services the stand owns.
 *
 * Every line is a container name and its compose service, split by the tab the format asked for.
 * A line whose service is not the stand's belongs to something else sharing the compose project —
 * the owner's preview lane — and dropping it here is the whole reason the service column is
 * printed at all. A line with no service column at all is likewise not the stand's.
 *
 * @param string $output What `standResidueContainersCommand()` printed.
 * @param array<int, string>|null $services The stand's services; null keeps every line, which is
 *     right for a stand whose project holds nothing but itself.
 * @return array<int, string>
 */
function teardownContainerNames(string $output, ?array $services): array
{
    $names = [];
    foreach (explode("\n", $output) as $line) {
        $columns = explode("\t", $line, 2);
        $name = trim($columns[0]);
        if ($name === '') {
            continue;
        }
        if ($services !== null && (count($columns) < 2 || !in_array(trim($columns[1]), $services, true))) {
            continue;
        }
        $names[] = $name;
    }

    return $names;
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
