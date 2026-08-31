<?php

declare(strict_types=1);

/**
 * What one step of a test run left behind, taken beside the verdict that step just
 * got, so that reading red starts from evidence instead of from a guess.
 *
 * Until this file, a red step was argued from memory: the log said a spec timed
 * out, the next step had already torn the stand down, and whether the node came up
 * frozen, never came up at all, or came up and stopped answering was three
 * hypotheses with nothing to tell them apart. The runner is the only place where
 * the step's id, its working directory, its exit code and a stand that is still
 * standing are all in hand at once, which is why the collecting lives here and not
 * in the wrapper or in a composer script (HIL-841).
 *
 * A snapshot is taken at EVERY outcome rather than only at red. A rule that fires
 * sometimes grows a second question — "why is this one empty?" — and the price of
 * dropping the condition is measured rather than assumed: a whole green run is
 * about 2.5 MB against the 139 MB the run store already holds. What the outcome
 * decides is the WORD in the header, and from that word whether there is anything
 * to triage at all.
 *
 * Collecting never changes a verdict. Docker refusing to answer, a directory that
 * cannot be written, a stand that is already down — each is a line under the
 * header's `missing` key, never a different exit code and never a stopped run.
 *
 * This file only declares functions and executes nothing, so that
 * `scripts/run-test-suite.php` and `framework/tests/Unit/StepArtifactsTest.php` can
 * both require it.
 */

/**
 * How long one external command the collector runs may take before it is killed.
 * Docker answers a healthy box in under a second; a probe still silent after half a
 * minute is answering the question by not answering it.
 */
const ARTIFACT_COMMAND_TIMEOUT_SECONDS = 30;

/**
 * How long the whole snapshot of one step may take. Past this the remaining phases
 * are skipped and say so, because a run that waits on its own evidence gathering
 * costs more than the evidence is worth.
 */
const ARTIFACT_BUDGET_SECONDS = 180;

/** Permissions for an artifact directory the runner or the collector has to create. */
const ARTIFACT_DIR_MODE = 0o755;

/** How often a command the collector started is looked at again, in microseconds. */
const ARTIFACT_POLL_INTERVAL_MICROSECONDS = 100_000;

/**
 * The signal sent to a command that overran its own timeout. Not a polite one: the
 * collector is leaving that command behind either way, and a probe that ignored
 * `TERM` would only spend the whole snapshot's budget dying.
 */
const ARTIFACT_KILL_SIGNAL = 9;

/**
 * How several entries share the one line their key gets in the header. The header
 * is `key: value` per line, so a list is joined rather than wrapped, and a
 * semicolon survives values that already carry commas and colons.
 */
const ARTIFACT_VALUE_SEPARATOR = '; ';

/**
 * The compose file a demo's stand is defined by, relative to the step's own working
 * directory. There is no registry of demos behind this: the path is the same for all
 * three and is already spelled out that way in each demo's composer scripts.
 */
const STAND_COMPOSE_FILE = 'docker/docker-compose.test.yml';

/** Where a demo's daemon, workers and agents write, relative to the step's working directory. */
const STAND_LOG_DIR = 'data/logs-test';

/**
 * The stand's own files worth keeping, by glob. The patterns overlap on purpose —
 * `worker-*.log` matches an error log too — because listing what a run happens to
 * have produced is not the collector's job; resolving them and dropping the
 * duplicates is.
 *
 * `archive/` is not among them and is not descended into: on the tree it is 18 MB
 * against 188 KB of live logs, and it describes runs that are not this one.
 *
 * @var array<int, string>
 */
const STAND_LOG_PATTERNS = [
    'daemon.log',
    'daemon-error.log',
    'worker-*.log',
    'worker-*.error.log',
    'agent-*.log',
    'agent-*.error.log',
    'protected-mode.state.json',
    'protected-mode.state.json.tmp',
];

/**
 * Where Playwright leaves its report and its per-test output, relative to the
 * step's working directory, and where each lands inside the snapshot. The retry
 * trace under `test-results` is the only evidence a flaky test ever leaves, and
 * today the next run of the same demo overwrites it.
 *
 * @var array<string, string>
 */
const PLAYWRIGHT_OUTPUT_DIRS = [
    'tests/e2e/playwright-report' => 'playwright/report',
    'tests/e2e/test-results' => 'playwright/test-results',
];

/** The header of a snapshot, the one file the collector always writes itself. */
const ARTIFACT_SNAPSHOT_FILE = 'SNAPSHOT.txt';

/**
 * The triage, written only for a step that has something to explain. The name is
 * the one the ticket gave it and is kept even though the file now appears for a
 * flaky green step too: it is the name the playbook and the run store already say.
 */
const ARTIFACT_TRIAGE_FILE = 'RED-SUMMARY.txt';

/** The word a step earns when there is nothing about it to explain. */
const ARTIFACT_REASON_GREEN = 'green';

/**
 * What a node writes when it restarts into a freeze it never left; the framework
 * prints it from `framework/backend/Core/Daemon/DaemonManager.php`. A step behind
 * this line was judged on a stand that could not have passed it.
 */
const FROZEN_STAND_SIGNATURE = 'Protected mode: this node came up still frozen for';

/**
 * What a demo's Playwright global setup throws when the stack never answers it. It
 * says the waiting ended and nothing about why, which is exactly why the two
 * hypotheses below it have to be told apart by docker.
 */
const STAND_NOT_READY_SIGNATURE = 'did not become ready within';

/**
 * What makes a worker's error log worth quoting. Both words, because the two halves
 * of the framework's own reporting use one each.
 *
 * @var array<int, string>
 */
const WORKER_ERROR_SIGNATURES = ['ERROR', 'Exception'];

/** How much of a worker's error log is lifted into the triage before it stops being a summary. */
const WORKER_ERROR_HEAD_LINES = 5;

/**
 * Where a reader looks inside a snapshot when nothing points anywhere in particular,
 * in the order worth trying. Only the entries this snapshot actually holds are named:
 * a red framework step has none of them, and sending its reader to a stand's log
 * would teach him that the triage does not know what it is looking at.
 *
 * @var array<int, string>
 */
const TRIAGE_READING_ORDER = ['stand/daemon.log', 'docker/ps.json', 'db/migration-status.txt', 'playwright/report'];

/**
 * How much of a container's output is kept. Five thousand lines reaches back past
 * a stand's whole boot on every demo, and a container that has said more than that
 * has said it about a run this snapshot is not about.
 */
const DOCKER_LOG_TAIL_LINES = 5000;

/**
 * The variable that turns the full database dump on for one run. Off by default:
 * the schema and exact row counts answer "did the data arrive" at a hundredth of
 * the size, and a dump is wanted only when the question is what the rows say.
 */
const ARTIFACT_DB_DUMP_VAR = 'HILOS_TEST_ARTIFACT_DB_DUMP';

/** The value of {@see ARTIFACT_DB_DUMP_VAR} that turns the dump on. */
const ARTIFACT_DB_DUMP_ON = '1';

/**
 * The schema without a single row of data. Read inside the container, where the
 * credentials already live, and handed to the client through `MYSQL_PWD` rather than
 * on a command line — a password on a command line is visible in the container's own
 * process list and earns a warning in the middle of the artifact.
 *
 * The client is named for MariaDB because the image is: `mariadb:11.4` no longer
 * ships the `mysql`-named symlinks, and a script calling `mysqldump` writes its own
 * `not found` into the artifact instead of a schema.
 */
const DATABASE_SCHEMA_QUERY = <<<'SH'
    set -e
    export MYSQL_PWD="$MYSQL_PASSWORD"
    exec mariadb-dump --no-data --single-transaction -u"$MYSQL_USER" "$MYSQL_DATABASE"
    SH;

/**
 * One `table<TAB>rows` line per table, counted rather than estimated. The estimate in
 * `information_schema.TABLE_ROWS` is worthless here: the question a red step raises is
 * whether the rows arrived at all, and that is the question the estimate answers badly.
 */
const DATABASE_ROW_COUNT_QUERY = <<<'SH'
    set -e
    export MYSQL_PWD="$MYSQL_PASSWORD"
    tables=$(mariadb -N -B -u"$MYSQL_USER" -D"$MYSQL_DATABASE" -e 'SHOW TABLES')
    for table in $tables; do
        printf '%s\t' "$table"
        mariadb -N -B -u"$MYSQL_USER" -D"$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM \`$table\`"
    done
    SH;

/** The data as well, for the one run that asked for it through {@see ARTIFACT_DB_DUMP_VAR}. */
const DATABASE_DUMP_QUERY = <<<'SH'
    set -e
    export MYSQL_PWD="$MYSQL_PASSWORD"
    exec mariadb-dump --single-transaction -u"$MYSQL_USER" "$MYSQL_DATABASE"
    SH;

/**
 * How a demo says which of its containers holds the database. A label rather than a
 * name, because the three demos call that service three different things
 * (`mysql-test`, `tasks-mysql-test`, `poll-mysql-test`) and a rule written on the
 * suffix of a name would break silently the first time a fourth demo spelled it
 * differently.
 */
const STAND_DATABASE_LABEL = 'hilos.role=database';

/**
 * How a demo says which of its containers runs the application, so that a command
 * of the demo's own CLI can be run inside a container that is ALREADY up. The
 * collector never starts one: `docker compose run` would pull the whole
 * `depends_on` chain up with it, and a stand raised by the collector is no longer
 * the stand under investigation.
 */
const STAND_APP_LABEL = 'hilos.role=app';

/**
 * Take one step's snapshot, and report where it went and what it says.
 *
 * The order of the phases is the order in which the evidence disappears. The facts
 * around the step are read first because they are true only now; the stand's files
 * are copied next because the next step of the same demo overwrites them; docker is
 * asked last because it is the one source that can be slow, and starting there
 * would mean not getting the rest.
 *
 * @param string $root Repository root; the step's `cwd` is relative to it.
 * @param string $id The step the snapshot is about.
 * @param array{cwd: string} $step Its manifest entry.
 * @param array{rc: int, at: string, seconds: float,
 *     unstable: array{count: int, tests: array<int, string>}} $finished How the step went.
 * @param array{lanes: int, logPath: string, neighborsAtStart: array<int, string>,
 *     neighborsAtFinish: array<int, string>} $context What the run around the step looked like,
 *     and where the step's own captured output is — the triage reads it for the line a
 *     Playwright setup prints when it gives up on the stand.
 * @param string $artifactDir The directory all of this run's snapshots go under.
 * @return array{path: string, reason: string, standUp: bool, missing: array<int, string>}
 */
function collectStepArtifacts(
    string $root,
    string $id,
    array $step,
    array $finished,
    array $context,
    string $artifactDir,
): array {
    $deadline = microtime(true) + ARTIFACT_BUDGET_SECONDS;
    $path = $artifactDir . '/' . $id;
    $reason = artifactReason($finished['rc'], $finished['unstable']);
    $cwd = $root . '/' . $step['cwd'];
    if (!makeArtifactDir($path)) {
        return [
            'path' => $path,
            'reason' => $reason,
            'standUp' => false,
            'missing' => ['artifacts: could not create ' . $path],
        ];
    }

    $missing = [];
    $head = runArtifactCommand('head', 'git rev-parse --short HEAD 2>&1', $root, ARTIFACT_COMMAND_TIMEOUT_SECONDS);
    $status = runArtifactCommand('git-status', 'git status --short 2>&1', $root, ARTIFACT_COMMAND_TIMEOUT_SECONDS);
    $missing = [...$missing, ...$head['missing'], ...$status['missing']];

    $missing = [...$missing, ...copyStandLogs($cwd, $path)];
    foreach (PLAYWRIGHT_OUTPUT_DIRS as $source => $target) {
        $missing = [...$missing, ...copyArtifactTree('playwright', $cwd . '/' . $source, $path . '/' . $target)];
    }

    $composeFile = standComposeFile($root, $step);
    $stand = artifactBudgetLeft($deadline)
        ? probeStand($root, $step, $composeFile)
        : ['state' => 'down', 'raw' => '', 'containers' => [], 'missing' => ['docker: the snapshot ran out of its budget']];
    $missing = [...$missing, ...$stand['missing']];
    if ($stand['state'] === 'up' && $composeFile !== null) {
        $missing = [...$missing, ...collectDockerArtifacts($root, $step, $stand, $path, $deadline)];
        $missing = [...$missing, ...collectDatabaseArtifacts($root, $step, $composeFile, $stand, $path, $deadline)];
    }

    if (artifactReasonNeedsTriage($reason)) {
        file_put_contents($path . '/' . ARTIFACT_TRIAGE_FILE, artifactTriageText(
            $id,
            $reason,
            matchTriageSignatures(triageInput($path, readArtifactFile($context['logPath']), $stand['containers'])),
        ));
    }

    writeArtifactSnapshot($path . '/' . ARTIFACT_SNAPSHOT_FILE, [
        'step' => $id,
        'reason' => $reason,
        'rc' => (string)$finished['rc'],
        'started' => $finished['at'],
        'finished' => date('c'),
        'seconds' => sprintf('%.1f', $finished['seconds']),
        'stand' => $stand['state'],
        'lanes' => (string)$context['lanes'],
        'neighbors-at-start' => implode(ARTIFACT_VALUE_SEPARATOR, $context['neighborsAtStart']),
        'neighbors-at-finish' => implode(ARTIFACT_VALUE_SEPARATOR, $context['neighborsAtFinish']),
        'load' => artifactLoad(),
        'head' => trim($head['output']),
        'git-status' => implode(ARTIFACT_VALUE_SEPARATOR, artifactLines($status['output'])),
        'unstable' => artifactUnstableValue($finished['unstable']),
        'missing' => implode(ARTIFACT_VALUE_SEPARATOR, $missing),
    ]);

    return ['path' => $path, 'reason' => $reason, 'standUp' => $stand['state'] === 'up', 'missing' => $missing];
}

/**
 * The one word a snapshot's header opens with, and the only thing about a snapshot
 * that the step's outcome decides.
 *
 * A step can be both red and flaky, and then it is neither of the two alone: the
 * word is compound so that a reader looking for the flicker still finds this
 * snapshot when the step also failed outright.
 *
 * @param int $rc The step's exit code.
 * @param array{count: int} $unstable What the step reported about tests that only passed on a retry.
 * @return string One of `green`, `flaky`, `red`, `red+flaky`.
 */
function artifactReason(int $rc, array $unstable): string
{
    if ($rc !== 0) {
        return $unstable['count'] > 0 ? 'red+flaky' : 'red';
    }

    return $unstable['count'] > 0 ? 'flaky' : ARTIFACT_REASON_GREEN;
}

/**
 * Whether this step's word means there is something to explain. A clean green step
 * gets no triage file at all: a triage that is always there, and empty most of the
 * time, teaches the reader not to open it.
 *
 * @param string $reason What {@see artifactReason()} returned.
 * @return bool
 */
function artifactReasonNeedsTriage(string $reason): bool
{
    return $reason !== ARTIFACT_REASON_GREEN;
}

/**
 * The compose file of the stand this step drives, relative to the step's own
 * working directory, or null for a step that has no stand at all — the framework
 * suite, the frontend build, a demo's type check.
 *
 * @param string $root Repository root.
 * @param array{cwd: string} $step The step's manifest entry.
 * @return string|null
 */
function standComposeFile(string $root, array $step): ?string
{
    return is_file($root . '/' . $step['cwd'] . '/' . STAND_COMPOSE_FILE) ? STAND_COMPOSE_FILE : null;
}

/**
 * The line printed straight after a step's closing `=== END ... ===`, so that the
 * path to the evidence sits where the verdict is read rather than in a directory
 * someone has to think of looking in.
 *
 * A standing stand gets a second sentence with the command that opens it, because
 * that stand is about to be torn down by whatever runs next: the reader has minutes,
 * and looking up how to ask docker costs some of them.
 *
 * @param string $id The step the snapshot is about.
 * @param array{path: string, reason: string, standUp: bool} $result What the collecting produced.
 * @param string $cwd The step's working directory, as the manifest spells it.
 * @return string One line, without its newline.
 */
function artifactPointerLine(string $id, array $result, string $cwd): string
{
    $line = sprintf('--- artifacts %s (%s): %s', $id, $result['reason'], $result['path']);
    if (!$result['standUp']) {
        return $line;
    }

    return $line . sprintf(' — stand is UP: docker compose -f %s ps (from %s)', STAND_COMPOSE_FILE, $cwd);
}

/**
 * The run summary's list of snapshots, printed after the table of steps, or nothing
 * at all when there are none.
 *
 * Silence at zero is the same rule the flaky section keeps: a section printed every
 * run stops being read, and this one is worth reading precisely when it is not
 * empty of the step someone is looking for.
 *
 * @param array<string, array{reason: string, path: string}> $byStep What was collected, keyed by step
 *     id, in the order the summary listed the steps.
 * @return string Whole lines, ready to write.
 */
function artifactSummarySection(array $byStep): string
{
    if ($byStep === []) {
        return '';
    }

    $section = "=== artifacts ===\n";
    foreach ($byStep as $id => $result) {
        $section .= sprintf("  %-20s %-9s %s\n", $id, $result['reason'], $result['path']);
    }

    return $section;
}

/**
 * The hypotheses this snapshot fits, each one saying what to open to confirm it.
 *
 * Hypotheses and not verdicts: every entry names the evidence that would settle it,
 * because a triage that asserts is a triage that will one day assert something
 * false and be believed. Nothing here talks to docker or to the filesystem — it
 * reads texts that have already been collected, which is what makes the whole set
 * testable without a stand.
 *
 * The last entry has no condition. When nothing matched, silence would read as "all
 * clear", and all clear is the one thing a red step is not.
 *
 * The daemon's own log is what says a master ran at all. A step can have a demo's
 * log directory without ever starting a daemon — every `<demo>-check` does — so a
 * missing worker log is only news once the master has been heard from.
 *
 * @param array{daemonLog: string, workerErrors: array<string, string>,
 *     workerLogs: array<int, string>, stepLog: string, holds: array<int, string>,
 *     containers: array<int, array<string, mixed>>} $collected What was gathered: the stand's
 *     daemon log, its workers' error logs by file name, the names of its workers' plain logs,
 *     the step's own captured output, which of the snapshot's places exist, and the rows
 *     `docker compose ps` returned.
 * @return array<int, string> One paragraph per hypothesis, in the order to consider them.
 */
function matchTriageSignatures(array $collected): array
{
    $matched = [];
    if (str_contains($collected['daemonLog'], FROZEN_STAND_SIGNATURE)) {
        $matched[] = 'The stand came up already frozen: daemon.log carries "' . FROZEN_STAND_SIGNATURE
            . '". This step was judged on a node that could not have passed it, so its verdict is'
            . " not about this step.\n"
            . '  Check: stand/protected-mode.state.json in this snapshot, and its time against'
            . ' `started` in SNAPSHOT.txt.';
    }
    if (str_contains($collected['stepLog'], STAND_NOT_READY_SIGNATURE)) {
        $matched[] = triageOfAStandThatWasWaitedFor($collected['containers']);
    }
    foreach ($collected['workerErrors'] as $file => $text) {
        $head = triageWorkerErrorHead($text);
        if ($head !== '') {
            $matched[] = 'A worker reported an error, in stand/' . $file . ":\n" . $head
                . "\n  Check: this is the first thing to read — a worker's error log is what found both"
                . ' roots of HIL-717.';
        }
    }
    if ($collected['daemonLog'] !== '' && $collected['workerLogs'] === []) {
        $matched[] = 'The master forked no workers: this stand\'s daemon wrote a log, and not one worker'
            . " did.\n"
            . '  Check: stand/daemon.log from its first line, where the master says what it started.';
    }

    return $matched === [] ? [triageOfNothingMatched($collected['holds'])] : $matched;
}

/**
 * The hypotheses a step that gave up waiting for its stand is between, told apart by
 * what docker was holding at the time.
 *
 * This is the fork that cost HIL-717 its third run: the waiting message is the same
 * whichever way it went, so the run was read as a freeze, which could never have
 * produced that message at all.
 *
 * Holding NOTHING is its own answer and not the healthy one. Zero containers means
 * the collector has no picture of the stand — either the chain fell over before it
 * was raised, or it had already been torn down — and calling that "everything was
 * healthy" would be the same false comfort this whole file exists to end.
 *
 * @param array<int, array<string, mixed>> $containers What `docker compose ps` listed.
 * @return string
 */
function triageOfAStandThatWasWaitedFor(array $containers): string
{
    if ($containers === []) {
        return 'The step waited for a stand that was not there: it gave up ("' . STAND_NOT_READY_SIGNATURE
            . '") and docker was holding no container of this project at all, so what it was waiting for'
            . " had either never been raised or was already gone.\n"
            . '  Check: SNAPSHOT.txt says `stand`, and the step log above the waiting says how far the'
            . ' chain that raises the stand got.';
    }

    $unhealthy = unhealthyStandContainers($containers);
    if ($unhealthy !== []) {
        return 'The stack never came up: the step waited for it and gave up ("' . STAND_NOT_READY_SIGNATURE
            . '"), and docker was holding ' . count($unhealthy) . ' container(s) neither running nor healthy: '
            . implode(ARTIFACT_VALUE_SEPARATOR, $unhealthy) . ".\n"
            . '  Check: docker/logs/ of those containers. The Playwright report records only the waiting,'
            . ' so it has nothing to say here.';
    }

    return 'The daemon came up and stopped answering: the step gave up waiting ("'
        . STAND_NOT_READY_SIGNATURE . '") while every container docker held was running and healthy.'
        . "\n  Check: the tail of stand/daemon.log, and db/migration-status.txt — a schema behind the code"
        . ' is one way a node stands there and answers nothing.';
}

/**
 * What to say when no signature fits, which is a statement rather than an absence.
 *
 * The reading order names only what is there. A step with no stand — the framework
 * suite, the frontend build — is the commonest red of all, and pointing its reader at
 * a daemon log that was never written is worse than pointing him nowhere.
 *
 * @param array<int, string> $holds The places inside this snapshot that exist.
 * @return string
 */
function triageOfNothingMatched(array $holds): string
{
    return "No signature matched. That is not a clean bill of health: it means this snapshot holds\n"
        . "nothing the collector has been taught to recognise, and the reading starts from scratch.\n"
        . '  Check, in this order: ' . implode(', ', [ARTIFACT_SNAPSHOT_FILE, ...$holds])
        . ", and the step's own log beside this snapshot.";
}

/**
 * The error a worker's log reports and the lines under it, so that the triage carries
 * the error itself rather than a pointer to it.
 *
 * Quoted from the reporting line and not from the top of the file: deciding by one
 * line and quoting another is how a triage ends up saying "a worker reported an
 * error" above five lines in which nothing is wrong.
 *
 * @param string $text The error log.
 * @return string The quoted lines, indented, or an empty string when the log says nothing.
 */
function triageWorkerErrorHead(string $text): string
{
    $lines = artifactLines($text);
    foreach ($lines as $index => $line) {
        if (triageLineReportsAnError($line)) {
            return implode("\n", array_map(
                static fn(string $quoted): string => '    ' . $quoted,
                array_slice($lines, $index, WORKER_ERROR_HEAD_LINES),
            ));
        }
    }

    return '';
}

/**
 * Whether one line of a log is the framework reporting a failure.
 *
 * @param string $line The line.
 * @return bool
 */
function triageLineReportsAnError(string $line): bool
{
    foreach (WORKER_ERROR_SIGNATURES as $signature) {
        if (str_contains($line, $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * The services docker was holding in a state the stand cannot work in — stopped,
 * or running but failing its own health check.
 *
 * @param array<int, array<string, mixed>> $containers What `docker compose ps` listed.
 * @return array<int, string> Their service names.
 */
function unhealthyStandContainers(array $containers): array
{
    $wrong = [];
    foreach ($containers as $container) {
        $service = $container['Service'] ?? null;
        if (!is_string($service)) {
            continue;
        }
        if (($container['State'] ?? null) !== 'running' || ($container['Health'] ?? null) === 'unhealthy') {
            $wrong[] = $service;
        }
    }

    return $wrong;
}

/**
 * Gather the texts the signatures read, out of what has already been copied into the
 * snapshot. The copies rather than the sources on purpose: the triage then describes
 * the same bytes the reader will open, even if the stand writes another line a
 * second later.
 *
 * @param string $path The step's snapshot directory.
 * @param string $stepLog The step's own captured output.
 * @param array<int, array<string, mixed>> $containers What `docker compose ps` listed.
 * @return array{daemonLog: string, workerErrors: array<string, string>,
 *     workerLogs: array<int, string>, stepLog: string, holds: array<int, string>,
 *     containers: array<int, array<string, mixed>>}
 */
function triageInput(string $path, string $stepLog, array $containers): array
{
    $errorFiles = glob($path . '/stand/worker-*.error.log') ?: [];
    $workerErrors = [];
    foreach ($errorFiles as $file) {
        $workerErrors[basename($file)] = readArtifactFile($file);
    }
    // `worker-*.log` matches an error log too, so the plain ones are what is left.
    $plain = array_diff(glob($path . '/stand/worker-*.log') ?: [], $errorFiles);

    return [
        'daemonLog' => readArtifactFile($path . '/stand/daemon.log'),
        'workerErrors' => $workerErrors,
        'workerLogs' => array_values(array_map('basename', $plain)),
        'stepLog' => $stepLog,
        'holds' => array_values(array_filter(
            TRIAGE_READING_ORDER,
            static fn(string $place): bool => file_exists($path . '/' . $place),
        )),
        'containers' => $containers,
    ];
}

/**
 * The triage file: what this step is, and every hypothesis that fits it.
 *
 * @param string $id The step.
 * @param string $reason The word from its header.
 * @param array<int, string> $matched What {@see matchTriageSignatures()} returned.
 * @return string Whole lines, ready to write.
 */
function artifactTriageText(string $id, string $reason, array $matched): string
{
    return sprintf("=== %s (%s) ===\n\n", $id, $reason) . implode("\n\n", $matched) . "\n";
}

/**
 * Whether docker is holding anything for this step, and what it is holding.
 *
 * The state is asked of docker rather than deduced from the outcome, because "the
 * stack never came up" and "the stack came up and the tests failed" are the two
 * answers a red e2e step is most often between, and only docker can tell them
 * apart. A probe that cannot answer leaves the stand called `down`: the collector
 * may not claim a stand it did not see, and the reason it could not see one is
 * written into `missing` beside it.
 *
 * @param string $root Repository root.
 * @param array{cwd: string} $step The step's manifest entry.
 * @param string|null $composeFile What {@see standComposeFile()} found.
 * @return array{state: string, raw: string, containers: array<int, array<string, mixed>>,
 *     missing: array<int, string>}
 */
function probeStand(string $root, array $step, ?string $composeFile): array
{
    if ($composeFile === null) {
        return ['state' => 'none', 'raw' => '', 'containers' => [], 'missing' => []];
    }

    $ran = runArtifactCommand(
        'docker',
        'docker compose -f ' . escapeshellarg($composeFile) . ' --profile "*" ps --all --format json 2>&1',
        $root . '/' . $step['cwd'],
        ARTIFACT_COMMAND_TIMEOUT_SECONDS,
    );
    $containers = $ran['missing'] === [] ? decodeComposePs($ran['output']) : [];
    $running = array_filter($containers, static fn(array $container): bool => ($container['State'] ?? null) === 'running');

    return [
        'state' => $running === [] ? 'down' : 'up',
        'raw' => $ran['output'],
        'containers' => $containers,
        'missing' => $ran['missing'],
    ];
}

/**
 * Keep what docker is holding: its own answer about the containers, and the tail of
 * each one's output.
 *
 * Only reachable while the stand still stands, which on a full run means a step that
 * went red before its chain reached `down`. On a green step there is nothing here to
 * take, and the stand is NOT raised again to get it — a stand the collector put up is
 * no longer the stand the run failed on.
 *
 * @param string $root Repository root.
 * @param array{cwd: string} $step The step's manifest entry.
 * @param array{raw: string, containers: array<int, array<string, mixed>>} $stand What
 *     {@see probeStand()} found.
 * @param string $path The step's snapshot directory.
 * @param float $deadline When the whole snapshot's budget runs out.
 * @return array<int, string> What could not be collected, with the reason.
 */
function collectDockerArtifacts(string $root, array $step, array $stand, string $path, float $deadline): array
{
    if (!makeArtifactDir($path . '/docker/logs')) {
        return ['docker: could not create ' . $path . '/docker/logs'];
    }
    file_put_contents($path . '/docker/ps.json', $stand['raw']);

    $cwd = $root . '/' . $step['cwd'];
    $missing = [];
    foreach ($stand['containers'] as $container) {
        $id = $container['ID'] ?? null;
        $service = $container['Service'] ?? null;
        if (!is_string($id) || !is_string($service)) {
            continue;
        }
        if (!artifactBudgetLeft($deadline)) {
            $missing[] = 'docker: out of budget before the logs of ' . $service;
            break;
        }
        // By id rather than by name: the id is what ps just handed over, and a
        // container renamed between the two calls would otherwise be logged as absent.
        $missing = [...$missing, ...runArtifactCommand(
            'docker',
            'docker logs --tail ' . DOCKER_LOG_TAIL_LINES . ' ' . escapeshellarg($id)
                . ' > ' . escapeshellarg($path . '/docker/logs/' . $service . '.log') . ' 2>&1',
            $cwd,
            ARTIFACT_COMMAND_TIMEOUT_SECONDS,
        )['missing']];
    }

    return $missing;
}

/**
 * Keep what the database says about itself: its schema, an exact count of every
 * table's rows, the demo's own view of its migrations, and — only when this run was
 * told to — the data as well.
 *
 * Every query runs INSIDE the database's container. The credentials are already in
 * that container's environment, so the collector never learns them and never carries
 * them across a command line; and the counts are real `COUNT(*)` rather than the
 * estimate in `information_schema`, because the question a red step raises is
 * precisely whether the rows arrived.
 *
 * @param string $root Repository root.
 * @param array{cwd: string} $step The step's manifest entry.
 * @param string $composeFile The stand's compose file, relative to the step's cwd.
 * @param array{containers: array<int, array<string, mixed>>} $stand What {@see probeStand()} found.
 * @param string $path The step's snapshot directory.
 * @param float $deadline When the whole snapshot's budget runs out.
 * @return array<int, string> What could not be collected, with the reason.
 */
function collectDatabaseArtifacts(
    string $root,
    array $step,
    string $composeFile,
    array $stand,
    string $path,
    float $deadline,
): array {
    $database = labelledStandService($stand['containers'], STAND_DATABASE_LABEL);
    if ($database === null) {
        return ['db: no container labelled ' . STAND_DATABASE_LABEL];
    }
    if (!makeArtifactDir($path . '/db')) {
        return ['db: could not create ' . $path . '/db'];
    }

    $missing = [];
    $queries = ['schema.sql' => DATABASE_SCHEMA_QUERY, 'row-counts.txt' => DATABASE_ROW_COUNT_QUERY];
    if (getenv(ARTIFACT_DB_DUMP_VAR) === ARTIFACT_DB_DUMP_ON) {
        $queries['dump.sql'] = DATABASE_DUMP_QUERY;
    }
    foreach ($queries as $file => $query) {
        $missing = [...$missing, ...runStandCommand(
            'db',
            $root,
            $step,
            $composeFile,
            $database,
            'sh -c ' . escapeshellarg($query),
            $path . '/db/' . $file,
            $deadline,
        )];
    }

    $app = labelledStandService($stand['containers'], STAND_APP_LABEL);
    if ($app === null) {
        return [...$missing, 'db: no container labelled ' . STAND_APP_LABEL];
    }

    return [...$missing, ...runStandCommand(
        'db',
        $root,
        $step,
        $composeFile,
        $app,
        'php backend/Bootstrap/cli.php db:migration:status',
        $path . '/db/migration-status.txt',
        $deadline,
    )];
}

/**
 * Run one command inside a container of the stand and put its whole output — the
 * error text included — into one file of the snapshot.
 *
 * `exec` and never `run`: `run` would start a container, and with it everything the
 * service depends on, which is the one thing collecting must not do.
 *
 * @param string $label What to blame in `missing`.
 * @param string $root Repository root.
 * @param array{cwd: string} $step The step's manifest entry.
 * @param string $composeFile The stand's compose file, relative to the step's cwd.
 * @param string $service The compose service to run it in.
 * @param string $command The command, already quoted for a shell.
 * @param string $target Where its output goes.
 * @param float $deadline When the whole snapshot's budget runs out.
 * @return array<int, string> What stopped it, or nothing when it ran.
 */
function runStandCommand(
    string $label,
    string $root,
    array $step,
    string $composeFile,
    string $service,
    string $command,
    string $target,
    float $deadline,
): array {
    if (!artifactBudgetLeft($deadline)) {
        return [$label . ': out of budget before ' . basename($target)];
    }

    $ran = runArtifactCommand(
        $label,
        'docker compose -f ' . escapeshellarg($composeFile) . ' exec -T ' . escapeshellarg($service) . ' ' . $command
            . ' > ' . escapeshellarg($target) . ' 2>&1',
        $root . '/' . $step['cwd'],
        ARTIFACT_COMMAND_TIMEOUT_SECONDS,
    );

    return array_map(static fn(string $reason): string => $reason . ' (' . basename($target) . ')', $ran['missing']);
}

/**
 * The compose service of the container carrying one of the stand's role labels, or
 * null when this stand has none.
 *
 * The labels arrive as one comma-joined `key=value` string, so the pair is matched
 * whole rather than parsed: a value elsewhere in that string containing a comma can
 * then split into anything at all without ever producing a false match.
 *
 * @param array<int, array<string, mixed>> $containers What `docker compose ps` listed.
 * @param string $label The `key=value` pair to look for.
 * @return string|null
 */
function labelledStandService(array $containers, string $label): ?string
{
    foreach ($containers as $container) {
        $labels = $container['Labels'] ?? null;
        $service = $container['Service'] ?? null;
        if (is_string($labels) && is_string($service) && in_array($label, explode(',', $labels), true)) {
            return $service;
        }
    }

    return null;
}

/**
 * What `docker compose ps --format json` said, as rows.
 *
 * Two shapes are read because docker has printed both: one JSON array of objects,
 * and one object per line. Anything that parses as neither is dropped rather than
 * guessed at — an unreadable answer means the collector knows nothing about the
 * containers, which is the same thing it knows when docker did not answer.
 *
 * @param string $output What the command printed.
 * @return array<int, array<string, mixed>>
 */
function decodeComposePs(string $output): array
{
    $whole = json_decode(trim($output), true);
    if (is_array($whole) && array_is_list($whole)) {
        return array_values(array_filter($whole, static fn(mixed $row): bool => is_array($row)));
    }

    $rows = [];
    foreach (artifactLines($output) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * Run one command for the collector, bounded, and report what stopped it from
 * answering — nothing at all when it answered.
 *
 * The output goes to a file rather than to a pipe: a pipe nobody drains fills and
 * deadlocks, and the collector has nothing useful to do while it waits.
 *
 * @param string $label What to blame in `missing`; it names the subject, not the binary,
 *     so two `git` calls do not report as one.
 * @param string $command A shell line.
 * @param string $cwd Where to run it.
 * @param int $timeoutSeconds How long it may take.
 * @return array{output: string, missing: array<int, string>}
 */
function runArtifactCommand(string $label, string $command, string $cwd, int $timeoutSeconds): array
{
    $outputPath = tempnam(sys_get_temp_dir(), 'hilos-artifact-');
    if ($outputPath === false) {
        return ['output' => '', 'missing' => [$label . ': no temporary file to capture the output in']];
    }

    $handle = proc_open($command, [1 => ['file', $outputPath, 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($handle)) {
        unlink($outputPath);

        return ['output' => '', 'missing' => [$label . ': could not be started']];
    }

    $deadline = microtime(true) + $timeoutSeconds;
    while (proc_get_status($handle)['running']) {
        if (microtime(true) > $deadline) {
            proc_terminate($handle, ARTIFACT_KILL_SIGNAL);
            proc_close($handle);
            $output = readArtifactFile($outputPath);
            unlink($outputPath);

            return ['output' => $output, 'missing' => [$label . ': timed out after ' . $timeoutSeconds . 's']];
        }
        usleep(ARTIFACT_POLL_INTERVAL_MICROSECONDS);
    }
    $rc = proc_close($handle);
    $output = readArtifactFile($outputPath);
    unlink($outputPath);

    return ['output' => $output, 'missing' => $rc === 0 ? [] : [$label . ': exited rc=' . $rc]];
}

/**
 * Copy the stand's own logs, whatever this demo happened to write. A step with no
 * stand directory copies nothing and says nothing about it: having no daemon is not
 * a failure to collect one.
 *
 * @param string $cwd The step's working directory, absolute.
 * @param string $path The step's snapshot directory.
 * @return array<int, string> What could not be copied, with the reason.
 */
function copyStandLogs(string $cwd, string $path): array
{
    $source = $cwd . '/' . STAND_LOG_DIR;
    if (!is_dir($source)) {
        return [];
    }
    if (!makeArtifactDir($path . '/stand')) {
        return ['stand: could not create ' . $path . '/stand'];
    }

    $found = [];
    foreach (STAND_LOG_PATTERNS as $pattern) {
        $found = [...$found, ...(glob($source . '/' . $pattern) ?: [])];
    }

    $missing = [];
    foreach (array_unique($found) as $file) {
        if (!is_readable($file) || !copy($file, $path . '/stand/' . basename($file))) {
            $missing[] = 'stand: could not copy ' . basename($file);
        }
    }

    return $missing;
}

/**
 * Copy one directory into the snapshot, whole. A source that is not there is not
 * reported: a step that never ran Playwright has no report to be missing.
 *
 * @param string $label What to blame in `missing`.
 * @param string $source The directory to copy.
 * @param string $target Where it goes.
 * @return array<int, string> What could not be copied, with the reason.
 */
function copyArtifactTree(string $label, string $source, string $target): array
{
    if (!is_dir($source)) {
        return [];
    }
    if (!makeArtifactDir($target)) {
        return [$label . ': could not create ' . $target];
    }

    $missing = [];
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($source . '/' . $entry)) {
            $missing = [...$missing, ...copyArtifactTree($label, $source . '/' . $entry, $target . '/' . $entry)];
            continue;
        }
        if (!is_readable($source . '/' . $entry) || !copy($source . '/' . $entry, $target . '/' . $entry)) {
            $missing[] = $label . ': could not copy ' . $source . '/' . $entry;
        }
    }

    return $missing;
}

/**
 * Write the header: one `key: value` per line, in the order the keys were given,
 * which is the order a reader goes down them.
 *
 * @param string $path Where the header goes.
 * @param array<string, string> $keys The header's keys and their rendered values.
 */
function writeArtifactSnapshot(string $path, array $keys): void
{
    $text = '';
    foreach ($keys as $key => $value) {
        $text .= $key . ': ' . $value . "\n";
    }
    file_put_contents($path, $text);
}

/**
 * Create a directory the snapshot needs, and say whether it is there afterwards. A
 * directory another phase already made is a success, not a race to report.
 *
 * @param string $path The directory.
 * @return bool
 */
function makeArtifactDir(string $path): bool
{
    return is_dir($path) || mkdir($path, ARTIFACT_DIR_MODE, true) || is_dir($path);
}

/**
 * A file the collector wrote or copied, or an empty string when it cannot be read
 * back. Absence is the answer here rather than an error: a command that printed
 * nothing and a command whose output went missing are equally silent.
 *
 * @param string $path The file.
 * @return string
 */
function readArtifactFile(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }
    $contents = file_get_contents($path);

    return $contents === false ? '' : $contents;
}

/**
 * The non-empty lines of some output, so that a header value made of them carries
 * no blanks.
 *
 * @param string $text The output.
 * @return array<int, string>
 */
function artifactLines(string $text): array
{
    $lines = preg_split('/\R/', trim($text));
    if ($lines === false) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
}

/**
 * Whether there is any of the snapshot's budget left to start another phase with.
 *
 * @param float $deadline When the budget runs out, as `microtime(true)`.
 * @return bool
 */
function artifactBudgetLeft(float $deadline): bool
{
    return microtime(true) < $deadline;
}

/**
 * This machine's load as `/proc/loadavg` states it, whole, or an empty value on a
 * platform that has no such file. The line is taken as it stands rather than picked
 * apart: the running and total process counts at its end answer "was it crowded"
 * as directly as the three averages do.
 *
 * @return string
 */
function artifactLoad(): string
{
    return trim(readArtifactFile('/proc/loadavg'));
}

/**
 * The flaky count and the names beside it, joined the way every other list in the
 * header is. The names sit inside parentheses after the count, which is the one thing
 * a reader needs from this line: a step reporting none says so with a bare zero.
 *
 * @param array{count: int, tests: array<int, string>} $unstable What the step reported.
 * @return string
 */
function artifactUnstableValue(array $unstable): string
{
    if ($unstable['count'] === 0) {
        return (string)$unstable['count'];
    }

    return $unstable['count'] . ' (' . implode(ARTIFACT_VALUE_SEPARATOR, $unstable['tests']) . ')';
}
