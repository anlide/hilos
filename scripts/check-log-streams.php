<?php

declare(strict_types=1);

/**
 * Prove where every log line of a node lands, on a live stand, as a repeatable check (HIL-1018).
 *
 * The HIL-872 map (`hilos-ops/maps/HIL-872-log-streams.md`) was measured once, by hand, on a
 * script outside the repository that nobody runs. This command is that measurement as a step
 * of the run: it stands `demo/tasks` up, provokes each log source on purpose, and asserts WHERE
 * the line landed — a file of the log directory, the container log, or nowhere — against the
 * table in `scripts/log-streams.php`. It goes red when a stream moves. The judging is in
 * `scripts/log-stream-verdicts.php`; everything that talks to docker is here and nowhere else.
 *
 * Five scenarios, in sequence and never overlapping — two daemon containers over one database
 * and one log directory would file each other's lines. Each one arms itself, marks the streams,
 * provokes its sources, polls every `lands` expectation until it arrives or the deadline passes,
 * takes the closing snapshot, and only then judges the `never` half — a line still in flight
 * would read as absent, and the check would pass for the wrong reason.
 *
 *   alive             stand up, wait for the daemon over its command socket, ask it for one line.
 *   crash             one SIGKILL of the master, on the same stand.
 *   master-error      the probe (`scripts/log-stream-probe.php`) installed into the container's
 *                     own layer, the master restarted under it, then a real warning and a real
 *                     fatal by signal.
 *   agent-in-master   a daemon container of its own, with the freeze silence timeout lowered and
 *                     nobody to alert; a freeze entered through the live initiator and left idle
 *                     until the master's own watchdog writes an agent line.
 *   rotation-refused  a daemon container of its own, with a file of the host bind-mounted INTO
 *                     the log directory: rename() cannot move a mount point even as root. Chosen
 *                     to reach the rotation complaint of map row 3, and found on the first live
 *                     run to reach the watchdog's own PHP error handler instead (row 18): rename()
 *                     warns before it returns false, and the watchdog leaves (P-353).
 *
 * Readiness is asked over the command socket (`cli.php daemon:status`, run inside the daemon's
 * container where the daemon addresses itself), never read off the log files — a stream that
 * moved would otherwise show up as a timeout with no verdict, the one red that teaches nothing.
 *
 * What red looks like: one block per disappointed expectation, naming the map row, the scenario,
 * the literal pattern, which stream disappointed it and how, and the tail of every stream the
 * record names. A failure of the check ITSELF — docker silent, a container that never became
 * ready, a probe that could not be installed — is its own red with its own wording and its own
 * exit code: "the stand did not come up" and "the stream moved" are read by different people.
 *
 * The stand is left as it was found: the log directory is reset where a scenario needs a clean
 * one, every container the check started is stopped by the check, the probe and its ini are
 * removed, the freeze state file is deleted, and the stand is dropped at the end whatever
 * happened — a red must not leave a daemon eating cores.
 *
 * Usage:
 *   composer run test:log-streams                      from the repository root, alone
 *   php scripts/run-test-suite.php log-streams         the step of the full run, after the
 *                                                      cluster step its ordering edge names
 *
 * Exit codes: 0 every expectation held; 1 at least one was disappointed; 2 the check could not
 * do its job (the table is broken, or the harness failed).
 */

/** Every expectation held. */
const LOG_STREAMS_GREEN = 0;

/** At least one expectation of the table was disappointed: a stream moved. */
const LOG_STREAMS_RED = 1;

/** The check could not do its job — a broken table, or a harness that failed — and said so. */
const LOG_STREAMS_HARNESS = 2;

/** The stand the check runs on, by its id in `scripts/test-stands.php`. */
const LOG_STREAMS_STAND_ID = 'tasks';

/**
 * The compose service the daemon runs as. It is also the container name the stand gives it and
 * the network alias the cli container reaches it by, so the own-container scenarios keep the
 * alias with `--use-aliases`.
 */
const LOG_STREAMS_DAEMON_SERVICE = 'tasks-daemon-test';

/** The compose service that runs a command against a stand whose daemon is down. */
const LOG_STREAMS_CLI_SERVICE = 'tasks-cli-test';

/** The compose profile the cli service sits behind. */
const LOG_STREAMS_CLI_PROFILE = 'cli';

/** The name the two own-container scenarios start the daemon service under. */
const LOG_STREAMS_OWN_CONTAINER = 'tasks-daemon-log-streams';

/** Where the log directory is mounted inside the daemon container. */
const LOG_STREAMS_CONTAINER_LOG_DIR = '/var/log/hilos';

/** The demo's CLI, run inside a container of the stand. */
const LOG_STREAMS_CLI = 'php backend/Bootstrap/cli.php';

/** The variable the stand pins its monopolistic pool by; readiness means the pool is full. */
const LOG_STREAMS_MONOPOLISTIC_MIN_VAR = 'WORKER_MIN_MONOPOLISTIC';

/**
 * How the master is found inside its container: the one cmdline holding this, read off /proc.
 * A `pgrep` would match the searching process itself; the bracket keeps this string from matching
 * the shell that carries it.
 */
const LOG_STREAMS_DAEMON_CMDLINE = 'Bootstrap/daemon[.]php';

/** The probe, relative to the repository root. */
const LOG_STREAMS_PROBE_SOURCE = 'scripts/log-stream-probe.php';

/** Where the probe goes inside the container: its own layer, never the bind-mounted tree. */
const LOG_STREAMS_PROBE_TARGET = '/tmp/hilos-log-stream-probe.php';

/** The ini that names the probe as `auto_prepend_file`; `zz-` so it loads after the extensions. */
const LOG_STREAMS_PROBE_INI = '/usr/local/etc/php/conf.d/zz-hilos-log-stream-probe.ini';

/** The name the host file is mounted under inside the log directory; the row 3 pattern names it. */
const LOG_STREAMS_PIN_NAME = 'pinned-by-log-stream-check.log';

/** The operation the driven freeze protects; the row 13 pattern names it. */
const LOG_STREAMS_FREEZE_OPERATION = 'log-stream-check';

/** The variable that says how long a freeze may stay silent before the master's watchdog reports it. */
const LOG_STREAMS_SILENCE_TIMEOUT_VAR = 'HILOS_PROTECTED_MODE_SILENCE_TIMEOUT';

/** The couple of seconds the scenario gives the idle freeze before that report is due. */
const LOG_STREAMS_SILENCE_TIMEOUT_SECONDS = 2;

/** The variable that names who is alerted; emptied so that the notifier writes that nobody is. */
const LOG_STREAMS_ALERT_EMAILS_VAR = 'HILOS_PROTECTED_MODE_ALERT_EMAILS';

/** The freeze state files a stand left frozen would poison the next run with. */
const LOG_STREAMS_STATE_FILE_GLOB = 'protected-mode.state.json*';

/** The line asked for through the live daemon; the row 11 pattern names it. */
const LOG_STREAMS_APPEND_MESSAGE = 'hilos log stream check';

/** How many of that line: one is enough to prove the path. */
const LOG_STREAMS_APPEND_COUNT = 1;

/** The signal that kills the master outright, by the name the container's `kill` takes. */
const LOG_STREAMS_SIGNAL_KILL = 'KILL';

/** The signal the probe answers with a real warning. */
const LOG_STREAMS_SIGNAL_WARNING = 'USR2';

/** The signal the probe answers with a real fatal. */
const LOG_STREAMS_SIGNAL_FATAL = 'USR1';

/** How long a command that raises or drops the stand may take; the first one may build an image. */
const LOG_STREAMS_STAND_TIMEOUT_SECONDS = 900;

/** How long one docker command against a standing stand may take. */
const LOG_STREAMS_COMMAND_TIMEOUT_SECONDS = 60;

/**
 * How long the daemon is given to answer with its full monopolistic pool. It has to cover the
 * framework's own restart wait after a crash (DAEMON_MIN_RESTART_INTERVAL) plus the pool coming up.
 */
const LOG_STREAMS_READY_DEADLINE_SECONDS = 240;

/** How long a scenario's `lands` expectations are given to arrive once provoked. */
const LOG_STREAMS_LANDING_DEADLINE_SECONDS = 120;

/** How often the streams are looked at again while a landing is awaited. */
const LOG_STREAMS_POLL_INTERVAL_MICROSECONDS = 500_000;

/** How often the daemon is asked again while its readiness is awaited; a status call costs a process. */
const LOG_STREAMS_READY_POLL_INTERVAL_MICROSECONDS = 2_000_000;

/**
 * How long after the master died the workers' farewell lines are given to appear anywhere.
 * A negative-only row is weaker by nature; the window is what makes its "nowhere" a claim.
 */
const LOG_STREAMS_FAREWELL_WINDOW_SECONDS = 3;

/** The map row whose index-continuation half is asserted over the snapshot's names, not by a record. */
const LOG_STREAMS_WORKER_INDEX_ROW = 10;

/** The map row the warning record of master-error proves; polled before the fatal is provoked. */
const LOG_STREAMS_WARNING_ROW = 19;

$root = dirname(__DIR__);
require_once $root . '/scripts/stand-registry.php';
require_once $root . '/scripts/step-artifacts.php';
require_once $root . '/scripts/log-stream-verdicts.php';

exit(checkLogStreams($root, require $root . '/scripts/log-streams.php'));

/**
 * Walk the five scenarios, print what went wrong, drop the stand, and say how it went.
 *
 * @param string $root Repository root.
 * @param array<int, mixed> $records What `scripts/log-streams.php` returned.
 * @return int One of the three exit codes.
 */
function checkLogStreams(string $root, array $records): int
{
    $problems = validateLogStreamRecords($records);
    if ($problems !== []) {
        fwrite(STDERR, "log-streams: HARNESS: the table in scripts/log-streams.php is broken:\n  " . implode("\n  ", $problems) . "\n");

        return LOG_STREAMS_HARNESS;
    }
    $stand = standById($root, LOG_STREAMS_STAND_ID);
    if ($stand === null) {
        fwrite(STDERR, 'log-streams: HARNESS: no stand ' . LOG_STREAMS_STAND_ID . " in scripts/test-stands.php\n");

        return LOG_STREAMS_HARNESS;
    }

    $box = [
        'root' => $root,
        'cwd' => $root . '/' . $stand['cwd'],
        'compose' => 'docker compose -f ' . escapeshellarg($stand['composeFile']),
        'logDir' => $root . '/' . $stand['cwd'] . '/' . STAND_LOG_DIR,
    ];
    fwrite(STDOUT, sprintf(
        "=== log-streams: %d records over map rows %s, %d scenarios, stand %s ===\n",
        count($records),
        implode(' ', logStreamRowsCovered($records)),
        count(LOG_STREAM_SCENARIOS),
        LOG_STREAMS_STAND_ID,
    ));

    $red = 0;
    $harness = null;
    $scenarios = [
        LOG_STREAM_SCENARIO_ALIVE => 'runAliveScenario',
        LOG_STREAM_SCENARIO_CRASH => 'runCrashScenario',
        LOG_STREAM_SCENARIO_MASTER_ERROR => 'runMasterErrorScenario',
        LOG_STREAM_SCENARIO_AGENT_IN_MASTER => 'runAgentInMasterScenario',
        LOG_STREAM_SCENARIO_ROTATION_REFUSED => 'runRotationRefusedScenario',
    ];
    foreach ($scenarios as $scenario => $runner) {
        $own = logStreamRecordsOf($records, $scenario);
        fwrite(STDOUT, sprintf("--- scenario %s: %d record(s)\n", $scenario, count($own)));
        $startedAt = microtime(true);
        $outcome = $runner($box, $own);
        fwrite(STDOUT, sprintf("    took %.1fs\n", microtime(true) - $startedAt));
        foreach ($outcome['failures'] as $failure) {
            fwrite(STDOUT, $failure);
            $red++;
        }
        if ($outcome['harness'] !== null) {
            $harness = $scenario . ': ' . $outcome['harness'];
            fwrite(STDERR, 'log-streams: HARNESS in scenario ' . $harness . "\n");
            break;
        }
    }

    dropStand($box);

    if ($harness !== null) {
        fwrite(STDOUT, "=== log-streams: HARNESS FAILURE — no verdict on the streams (" . $harness . ")\n");

        return LOG_STREAMS_HARNESS;
    }
    if ($red > 0) {
        fwrite(STDOUT, '=== log-streams: RED — ' . $red . " expectation(s) disappointed\n");

        return LOG_STREAMS_RED;
    }
    fwrite(STDOUT, '=== log-streams: green — map rows ' . implode(' ', logStreamRowsCovered($records)) . " proven\n");

    return LOG_STREAMS_GREEN;
}

// ------------------------------------------------------------------ scenarios

/**
 * Stand up, wait for the daemon, ask it for one line, judge the settled stand.
 *
 * The stand is dropped first: a container from an earlier run would carry an earlier run's
 * container log, and the marks below would then be taken on a stand that is not this one's.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @return array{failures: array<int, string>, harness: string|null}
 */
function runAliveScenario(array $box, array $records): array
{
    $raised = raiseStand($box);
    if ($raised !== null) {
        return ['failures' => [], 'harness' => $raised];
    }
    $marks = markStreams($box, LOG_STREAMS_DAEMON_SERVICE);
    $up = runFromStand($box, $box['compose'] . ' up -d ' . LOG_STREAMS_DAEMON_SERVICE, LOG_STREAMS_STAND_TIMEOUT_SECONDS);
    if (!$up['ok']) {
        return ['failures' => [], 'harness' => 'the daemon container did not start: ' . $up['note'] . "\n" . $up['output']];
    }
    $ready = waitDaemonReady(LOG_STREAMS_DAEMON_SERVICE);
    if ($ready !== null) {
        return ['failures' => [], 'harness' => $ready];
    }
    $appended = containerExec(LOG_STREAMS_DAEMON_SERVICE, LOG_STREAMS_CLI . ' ' . escapeshellarg('test:log:append') . ' '
        . escapeshellarg(LOG_STREAMS_APPEND_MESSAGE) . ' ' . LOG_STREAMS_APPEND_COUNT);
    if (!$appended['ok']) {
        return ['failures' => [], 'harness' => 'test:log:append was refused: ' . $appended['note'] . "\n" . $appended['output']];
    }

    $snapshot = awaitLandings($box, LOG_STREAMS_DAEMON_SERVICE, $marks, $records);
    $failures = judgeScenario($records, $snapshot);
    $shared = logStreamSharedWorkerIndexes($snapshot);
    if ($shared !== []) {
        $failures[] = sprintf(
            "RED map row %d, scenario %s: worker index(es) %s appear on both a regular and a monopolistic stream\n",
            LOG_STREAMS_WORKER_INDEX_ROW,
            LOG_STREAM_SCENARIO_ALIVE,
            implode(', ', $shared),
        );
    }

    return ['failures' => $failures, 'harness' => null];
}

/**
 * One SIGKILL of the master, on the stand `alive` left standing.
 *
 * The farewell records are judged only after a short window: they claim the lines are NOWHERE,
 * and a claim about absence made the instant the master died is a claim about nothing.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @return array{failures: array<int, string>, harness: string|null}
 */
function runCrashScenario(array $box, array $records): array
{
    $marks = markStreams($box, LOG_STREAMS_DAEMON_SERVICE);
    $killed = signalDaemon(LOG_STREAMS_DAEMON_SERVICE, LOG_STREAMS_SIGNAL_KILL);
    if ($killed !== null) {
        return ['failures' => [], 'harness' => $killed];
    }

    awaitLandings($box, LOG_STREAMS_DAEMON_SERVICE, $marks, $records);
    sleep(LOG_STREAMS_FAREWELL_WINDOW_SECONDS);
    $snapshot = takeSnapshot($box, LOG_STREAMS_DAEMON_SERVICE, $marks);

    return ['failures' => judgeScenario($records, $snapshot), 'harness' => null];
}

/**
 * The probe into the container's layer, the master restarted under it, a warning, a fatal.
 *
 * The marks are taken after the restart: the SIGKILL that restarts the master is a death that
 * printed nothing, and the scenario's own `never` says a death here must not be reported so.
 * The warning is awaited on its own record before the fatal is provoked — the fatal's records
 * cannot land yet, and waiting on them would only spend the deadline.
 *
 * The probe and its ini are removed whatever happened, before the outcome is returned.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @return array{failures: array<int, string>, harness: string|null}
 */
function runMasterErrorScenario(array $box, array $records): array
{
    $armed = installProbe($box);
    if ($armed !== null) {
        removeProbe();

        return ['failures' => [], 'harness' => $armed];
    }

    $outcome = provokeMasterErrors($box, $records);
    removeProbe();

    return $outcome;
}

/**
 * The provocations of `master-error`, once the probe is in place.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @return array{failures: array<int, string>, harness: string|null}
 */
function provokeMasterErrors(array $box, array $records): array
{
    // `crash` left the master dead and the watchdog inside its restart interval: the master
    // has to be back before it can be killed into a start under the probe.
    $back = waitDaemonReady(LOG_STREAMS_DAEMON_SERVICE);
    if ($back !== null) {
        return ['failures' => [], 'harness' => 'before the restart under the probe: ' . $back];
    }
    $restarted = signalDaemon(LOG_STREAMS_DAEMON_SERVICE, LOG_STREAMS_SIGNAL_KILL);
    if ($restarted !== null) {
        return ['failures' => [], 'harness' => $restarted];
    }
    $ready = waitDaemonReady(LOG_STREAMS_DAEMON_SERVICE);
    if ($ready !== null) {
        return ['failures' => [], 'harness' => 'after the restart under the probe: ' . $ready];
    }
    $marks = markStreams($box, LOG_STREAMS_DAEMON_SERVICE);

    $warningRecords = array_values(array_filter(
        $records,
        static fn(array $record): bool => in_array(LOG_STREAMS_WARNING_ROW, $record['rows'], true),
    ));
    $warned = signalDaemon(LOG_STREAMS_DAEMON_SERVICE, LOG_STREAMS_SIGNAL_WARNING);
    if ($warned !== null) {
        return ['failures' => [], 'harness' => $warned];
    }
    awaitLandings($box, LOG_STREAMS_DAEMON_SERVICE, $marks, $warningRecords);

    $ready = waitDaemonReady(LOG_STREAMS_DAEMON_SERVICE);
    if ($ready !== null) {
        return ['failures' => [], 'harness' => 'after the warning: ' . $ready];
    }
    $fatal = signalDaemon(LOG_STREAMS_DAEMON_SERVICE, LOG_STREAMS_SIGNAL_FATAL);
    if ($fatal !== null) {
        return ['failures' => [], 'harness' => $fatal];
    }
    $snapshot = awaitLandings($box, LOG_STREAMS_DAEMON_SERVICE, $marks, $records);

    return ['failures' => judgeScenario($records, $snapshot), 'harness' => null];
}

/**
 * A daemon container of its own, a freeze entered through the live initiator and left idle.
 *
 * The stand's daemon container goes down first — two daemons over one database and one log
 * directory would file each other's lines — and the log directory is reset while no daemon is up,
 * which is the only time the reset command allows it. Whatever happened, the freeze is lifted,
 * the container is removed and the state file deleted: a stand left frozen poisons the next run.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @return array{failures: array<int, string>, harness: string|null}
 */
function runAgentInMasterScenario(array $box, array $records): array
{
    $cleared = clearForOwnContainer($box);
    if ($cleared !== null) {
        return ['failures' => [], 'harness' => $cleared];
    }
    $marks = markStreams($box, LOG_STREAMS_OWN_CONTAINER);
    $started = startOwnContainer($box, '-e ' . escapeshellarg(LOG_STREAMS_SILENCE_TIMEOUT_VAR . '=' . LOG_STREAMS_SILENCE_TIMEOUT_SECONDS)
        . ' -e ' . escapeshellarg(LOG_STREAMS_ALERT_EMAILS_VAR . '='));
    if ($started !== null) {
        removeOwnContainer($box);

        return ['failures' => [], 'harness' => $started];
    }

    $outcome = provokeIdleFreeze($box, $marks, $records);
    containerExec(LOG_STREAMS_OWN_CONTAINER, LOG_STREAMS_CLI . ' ' . escapeshellarg('test:protected-mode:leave'));
    containerExec(LOG_STREAMS_OWN_CONTAINER, LOG_STREAMS_CLI . ' ' . escapeshellarg('test:protected-mode:open'));
    removeOwnContainer($box);
    deleteFreezeState($box);

    return $outcome;
}

/**
 * The provocation of `agent-in-master`, once its container is up.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param array{files: array<string, int>, 'container-log': int, 'container-log-stderr': int} $marks Where the streams stood.
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @return array{failures: array<int, string>, harness: string|null}
 */
function provokeIdleFreeze(array $box, array $marks, array $records): array
{
    $ready = waitDaemonReady(LOG_STREAMS_OWN_CONTAINER);
    if ($ready !== null) {
        return ['failures' => [], 'harness' => $ready];
    }
    $entered = containerExec(LOG_STREAMS_OWN_CONTAINER, LOG_STREAMS_CLI . ' ' . escapeshellarg('test:protected-mode:enter') . ' '
        . escapeshellarg(LOG_STREAMS_FREEZE_OPERATION));
    if (!$entered['ok']) {
        return ['failures' => [], 'harness' => 'the freeze was not entered: ' . $entered['note'] . "\n" . $entered['output']];
    }
    $snapshot = awaitLandings($box, LOG_STREAMS_OWN_CONTAINER, $marks, $records);

    return ['failures' => judgeScenario($records, $snapshot), 'harness' => null];
}

/**
 * A daemon container of its own, with a file of the host mounted into the log directory.
 *
 * The host file lives in the temporary directory and is removed by the scenario; nothing is
 * written into the tree, and no permission is touched: the trap is that rename(2) refuses to
 * move a mount point, for root as for anyone. What that provokes today is a PHP warning inside
 * the watchdog — its landing is what the scenario's record asserts — and a watchdog that leaves;
 * the container is dead by the time the closing snapshot is taken, which is fine for reading it.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @return array{failures: array<int, string>, harness: string|null}
 */
function runRotationRefusedScenario(array $box, array $records): array
{
    $cleared = clearForOwnContainer($box);
    if ($cleared !== null) {
        return ['failures' => [], 'harness' => $cleared];
    }
    $pin = tempnam(sys_get_temp_dir(), 'hilos-log-stream-pin-');
    if ($pin === false || file_put_contents($pin, "pinned by the log-stream check\n") === false) {
        return ['failures' => [], 'harness' => 'no host file to mount into the log directory'];
    }
    $marks = markStreams($box, LOG_STREAMS_OWN_CONTAINER);
    $started = startOwnContainer(
        $box,
        '-v ' . escapeshellarg($pin . ':' . LOG_STREAMS_CONTAINER_LOG_DIR . '/' . LOG_STREAMS_PIN_NAME),
    );
    if ($started !== null) {
        removeOwnContainer($box);
        unlink($pin);

        return ['failures' => [], 'harness' => $started];
    }

    $snapshot = awaitLandings($box, LOG_STREAMS_OWN_CONTAINER, $marks, $records);
    removeOwnContainer($box);
    unlink($pin);

    return ['failures' => judgeScenario($records, $snapshot), 'harness' => null];
}

// ------------------------------------------------------------------ the stand

/**
 * Drop whatever stands, then raise the database and reset the log directory and the database.
 *
 * `test:up` is the demo's own preparation and is used as such: it removes the freeze state file,
 * raises the database and empties the log tree through the cli container.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @return string|null What went wrong, or null.
 */
function raiseStand(array $box): ?string
{
    foreach (['test:down', 'test:up', 'test:db-prepare'] as $script) {
        $ran = runFromStand($box, 'composer run ' . $script, LOG_STREAMS_STAND_TIMEOUT_SECONDS);
        if (!$ran['ok']) {
            return 'composer run ' . $script . ' failed: ' . $ran['note'] . "\n" . $ran['output'];
        }
    }

    return null;
}

/**
 * Drop the whole stand through the one teardown every other run goes through.
 *
 * @param array{root: string} $box Where things are.
 */
function dropStand(array $box): void
{
    $ran = runArtifactCommand(
        'stand',
        'php scripts/down-stands.php ' . LOG_STREAMS_STAND_ID,
        $box['root'],
        LOG_STREAMS_STAND_TIMEOUT_SECONDS,
    );
    fwrite(STDOUT, $ran['output']);
}

/**
 * Stop and remove the stand's daemon container, then empty the log directory and delete the
 * freeze state, so an own-container scenario starts from nothing.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @return string|null What went wrong, or null.
 */
function clearForOwnContainer(array $box): ?string
{
    runFromStand($box, $box['compose'] . ' rm -f -s ' . LOG_STREAMS_DAEMON_SERVICE, LOG_STREAMS_COMMAND_TIMEOUT_SECONDS);
    removeOwnContainer($box);
    deleteFreezeState($box);
    $reset = runFromStand(
        $box,
        $box['compose'] . ' --profile ' . LOG_STREAMS_CLI_PROFILE . ' run --rm ' . LOG_STREAMS_CLI_SERVICE . ' '
            . LOG_STREAMS_CLI . ' ' . escapeshellarg('test:logs:reset'),
        LOG_STREAMS_STAND_TIMEOUT_SECONDS,
    );
    if (!$reset['ok']) {
        return 'the log directory could not be reset: ' . $reset['note'] . "\n" . $reset['output'];
    }

    return null;
}

/**
 * Start the daemon service as a container of its own, with extra arguments for `compose run`.
 *
 * `run` rather than `up`: the two scenarios need an environment and a mount the compose file
 * does not declare. `--use-aliases` keeps the service's network alias, which is how the daemon
 * is reached by name. Detached, and not `--rm`: the container log is read while it stands, and
 * the scenario removes it itself.
 *
 * @param array{root: string, cwd: string, compose: string, logDir: string} $box Where things are.
 * @param string $extra The `-e` and `-v` arguments, already quoted.
 * @return string|null What went wrong, or null.
 */
function startOwnContainer(array $box, string $extra): ?string
{
    $ran = runFromStand(
        $box,
        $box['compose'] . ' run -d --use-aliases --name ' . LOG_STREAMS_OWN_CONTAINER . ' ' . $extra . ' ' . LOG_STREAMS_DAEMON_SERVICE,
        LOG_STREAMS_STAND_TIMEOUT_SECONDS,
    );
    if (!$ran['ok']) {
        return 'the own daemon container did not start: ' . $ran['note'] . "\n" . $ran['output'];
    }

    return null;
}

/**
 * Remove the own daemon container, standing or not. Nothing to remove is not a failure.
 *
 * @param array{root: string} $box Where things are.
 */
function removeOwnContainer(array $box): void
{
    runArtifactCommand('docker', 'docker rm -f ' . LOG_STREAMS_OWN_CONTAINER, $box['root'], LOG_STREAMS_COMMAND_TIMEOUT_SECONDS);
}

/**
 * Delete the freeze state files from the log directory, by name, the way `test:up` does.
 *
 * @param array{logDir: string} $box Where things are.
 */
function deleteFreezeState(array $box): void
{
    foreach (glob($box['logDir'] . '/' . LOG_STREAMS_STATE_FILE_GLOB) ?: [] as $file) {
        unlink($file);
    }
}

/**
 * Copy the probe into the container's layer and name it as `auto_prepend_file`, then read the
 * setting back: a probe that is not in place would leave the scenario waiting on a warning that
 * never comes, and that is a harness failure, not a stream that moved.
 *
 * @param array{root: string} $box Where things are.
 * @return string|null What went wrong, or null.
 */
function installProbe(array $box): ?string
{
    $copied = runArtifactCommand(
        'docker',
        'docker cp ' . escapeshellarg($box['root'] . '/' . LOG_STREAMS_PROBE_SOURCE) . ' '
            . escapeshellarg(LOG_STREAMS_DAEMON_SERVICE . ':' . LOG_STREAMS_PROBE_TARGET),
        $box['root'],
        LOG_STREAMS_COMMAND_TIMEOUT_SECONDS,
    );
    if ($copied['missing'] !== []) {
        return 'the probe could not be copied into the container: ' . implode('; ', $copied['missing']) . "\n" . $copied['output'];
    }
    $written = containerExec(
        LOG_STREAMS_DAEMON_SERVICE,
        'printf ' . escapeshellarg('auto_prepend_file=' . LOG_STREAMS_PROBE_TARGET . '\n') . ' > ' . escapeshellarg(LOG_STREAMS_PROBE_INI),
    );
    if (!$written['ok']) {
        return 'the probe ini could not be written: ' . $written['note'] . "\n" . $written['output'];
    }
    $read = containerExec(LOG_STREAMS_DAEMON_SERVICE, 'php -r ' . escapeshellarg('echo ini_get("auto_prepend_file");'));
    if (!$read['ok'] || trim($read['output']) !== LOG_STREAMS_PROBE_TARGET) {
        return 'php does not see the probe as auto_prepend_file; it answered: ' . trim($read['output']);
    }

    return null;
}

/** Remove the probe and its ini from the container's layer. Nothing to remove is not a failure. */
function removeProbe(): void
{
    containerExec(
        LOG_STREAMS_DAEMON_SERVICE,
        'rm -f ' . escapeshellarg(LOG_STREAMS_PROBE_INI) . ' ' . escapeshellarg(LOG_STREAMS_PROBE_TARGET),
    );
}

// ------------------------------------------------------------------ the daemon

/**
 * Wait until the daemon answers over its command socket with its monopolistic pool full.
 *
 * Asked through `daemon:status` inside the daemon's own container, where the daemon addresses
 * itself; the pool size is read off the container's environment rather than spelled here, so the
 * stand's own number is the one waited for.
 *
 * @param string $container The daemon container.
 * @return string|null What went wrong, or null once the daemon is ready.
 */
function waitDaemonReady(string $container): ?string
{
    $pool = containerExec($container, 'printenv ' . LOG_STREAMS_MONOPOLISTIC_MIN_VAR);
    if (!$pool['ok'] || preg_match('/^\d+$/', trim($pool['output'])) !== 1) {
        return LOG_STREAMS_MONOPOLISTIC_MIN_VAR . ' could not be read from the container: ' . $pool['note'] . "\n" . $pool['output'];
    }
    $wanted = (int)trim($pool['output']);

    $deadline = microtime(true) + LOG_STREAMS_READY_DEADLINE_SECONDS;
    $last = '';
    while (microtime(true) < $deadline) {
        $status = containerExec($container, LOG_STREAMS_CLI . ' daemon:status');
        $last = $status['output'];
        if (
            preg_match('/\|\s*Status\s*\|\s*ONLINE\b/', $last) === 1
            && preg_match('/\|\s*Workers Mono\s*\|\s*(\d+)\b/', $last, $match) === 1
            && (int)$match[1] >= $wanted
        ) {
            return null;
        }
        usleep(LOG_STREAMS_READY_POLL_INTERVAL_MICROSECONDS);
    }

    return sprintf(
        "the daemon in %s did not answer daemon:status with %d monopolistic workers within %ds; last answer:\n%s",
        $container,
        $wanted,
        LOG_STREAMS_READY_DEADLINE_SECONDS,
        $last,
    );
}

/**
 * The pid of the master inside its container, found by walking /proc, or null when none runs.
 *
 * @param string $container The daemon container.
 */
function daemonPid(string $container): ?int
{
    $walk = 'for p in /proc/[0-9]*; do if tr "\0" " " <"$p/cmdline" 2>/dev/null | grep -q '
        . escapeshellarg(LOG_STREAMS_DAEMON_CMDLINE) . '; then echo "${p#/proc/}"; fi; done';
    $found = containerExec($container, $walk);
    $lines = artifactLines($found['output']);
    if ($lines === [] || preg_match('/^\d+$/', $lines[0]) !== 1) {
        return null;
    }

    return (int)$lines[0];
}

/**
 * Send one signal to the master.
 *
 * @param string $container The daemon container.
 * @param string $signal The signal, by the name the container's `kill` takes.
 * @return string|null What went wrong, or null.
 */
function signalDaemon(string $container, string $signal): ?string
{
    $pid = daemonPid($container);
    if ($pid === null) {
        return 'no daemon.php is running in ' . $container . ' to send SIG' . $signal . ' to';
    }
    $sent = containerExec($container, 'kill -' . $signal . ' ' . $pid);
    if (!$sent['ok']) {
        return 'SIG' . $signal . ' to pid ' . $pid . ' in ' . $container . ' failed: ' . $sent['note'] . "\n" . $sent['output'];
    }
    fwrite(STDOUT, sprintf("    SIG%s -> pid %d in %s\n", $signal, $pid, $container));

    return null;
}

// ------------------------------------------------------------------ the streams

/**
 * Where every stream stands now: the size of each file, and how many lines each container stream
 * holds. A snapshot taken against these marks holds only what came after.
 *
 * @param array{logDir: string} $box Where things are.
 * @param string $container The daemon container the scenario reads; it may not exist yet.
 * @return array{files: array<string, int>, 'container-log': int, 'container-log-stderr': int}
 */
function markStreams(array $box, string $container): array
{
    $files = [];
    foreach (liveLogFiles($box) as $name => $path) {
        $size = filesize($path);
        $files[$name] = $size === false ? 0 : $size;
    }

    return [
        'files' => $files,
        LOG_STREAM_CONTAINER_LOG => containerLogLineCount(containerLog($container, false)),
        LOG_STREAM_CONTAINER_LOG_STDERR => containerLogLineCount(containerLog($container, true)),
    ];
}

/**
 * How many lines a container stream holds; none for a container that does not exist yet.
 *
 * @param string|null $log What {@see containerLog()} returned.
 */
function containerLogLineCount(?string $log): int
{
    return $log === null ? 0 : count(logStreamLines($log));
}

/**
 * The streams as texts since the marks: every live `*.log` file of the log directory under its
 * basename, and the two container streams under their tokens — absent, not empty, while the
 * container does not exist.
 *
 * A file smaller than its mark was recreated since, and is taken whole — the same reading the
 * framework's own crash quote makes of a stream that was rotated under it.
 *
 * @param array{logDir: string} $box Where things are.
 * @param string $container The daemon container the scenario reads.
 * @param array{files: array<string, int>, 'container-log': int, 'container-log-stderr': int} $marks Where the streams stood.
 * @return array<string, string>
 */
function takeSnapshot(array $box, string $container, array $marks): array
{
    $snapshot = [];
    foreach ([LOG_STREAM_CONTAINER_LOG => false, LOG_STREAM_CONTAINER_LOG_STDERR => true] as $token => $stderr) {
        $log = containerLog($container, $stderr);
        if ($log !== null) {
            $snapshot[$token] = linesSince($log, $marks[$token]);
        }
    }
    foreach (liveLogFiles($box) as $name => $path) {
        $text = readArtifactFile($path);
        $mark = $marks['files'][$name] ?? 0;
        $snapshot[$name] = strlen($text) >= $mark ? substr($text, $mark) : $text;
    }

    return $snapshot;
}

/**
 * The live log files of the stand's log directory, keyed by basename. The staging and archive
 * subtrees are directories and are not matched.
 *
 * @param array{logDir: string} $box Where things are.
 * @return array<string, string> Basename to path.
 */
function liveLogFiles(array $box): array
{
    $files = [];
    foreach (glob($box['logDir'] . '/' . LOG_STREAM_TOKEN_WILDCARD . LOG_STREAM_FILE_EXTENSION) ?: [] as $path) {
        if (is_file($path)) {
            $files[basename($path)] = $path;
        }
    }

    return $files;
}

/**
 * One of the two streams of the container's PID 1, whole, or null for a container that does
 * not exist — which has no stream at all, rather than an empty one. The two are read apart
 * because the map tells them apart.
 *
 * @param string $container The container.
 * @param bool $stderr Whether to read stderr instead of stdout.
 */
function containerLog(string $container, bool $stderr): ?string
{
    $command = 'docker logs ' . escapeshellarg($container) . ($stderr ? ' 2>&1 1>/dev/null' : ' 2>/dev/null');
    $ran = runArtifactCommand('docker', $command, sys_get_temp_dir(), LOG_STREAMS_COMMAND_TIMEOUT_SECONDS);

    return $ran['missing'] === [] ? $ran['output'] : null;
}

/**
 * The text after the first N lines of a stream, as whole lines.
 *
 * @param string $text The stream.
 * @param int $skip How many lines the mark stood at.
 */
function linesSince(string $text, int $skip): string
{
    $since = '';
    foreach (array_slice(logStreamLines($text), $skip) as $line) {
        $since .= $line . "\n";
    }

    return $since;
}

/**
 * Poll the streams until every `lands` expectation of the records has arrived or the deadline
 * passes, and hand back the last snapshot — the closing one when everything landed.
 *
 * @param array{logDir: string} $box Where things are.
 * @param string $container The daemon container the scenario reads.
 * @param array{files: array<string, int>, 'container-log': int, 'container-log-stderr': int} $marks Where the streams stood.
 * @param array<int, array<string, mixed>> $records The records whose landings are awaited.
 * @return array<string, string> The last snapshot taken.
 */
function awaitLandings(array $box, string $container, array $marks, array $records): array
{
    $startedAt = microtime(true);
    $deadline = $startedAt + LOG_STREAMS_LANDING_DEADLINE_SECONDS;
    while (true) {
        $snapshot = takeSnapshot($box, $container, $marks);
        $pending = 0;
        foreach ($records as $record) {
            if (judgeLogStreamLandings($record, $snapshot) !== []) {
                $pending++;
            }
        }
        if ($pending === 0) {
            fwrite(STDOUT, sprintf("    every landing arrived after %.1fs\n", microtime(true) - $startedAt));

            return $snapshot;
        }
        if (microtime(true) >= $deadline) {
            fwrite(STDOUT, sprintf("    %d record(s) still not landed after %ds\n", $pending, LOG_STREAMS_LANDING_DEADLINE_SECONDS));

            return $snapshot;
        }
        usleep(LOG_STREAMS_POLL_INTERVAL_MICROSECONDS);
    }
}

/**
 * Every disappointed expectation of the records, rendered, against the closing snapshot.
 *
 * @param array<int, array<string, mixed>> $records The scenario's records.
 * @param array<string, string> $snapshot The closing snapshot.
 * @return array<int, string>
 */
function judgeScenario(array $records, array $snapshot): array
{
    $failures = [];
    foreach ($records as $record) {
        foreach (judgeLogStreamRecord($record, $snapshot) as $verdict) {
            $failures[] = renderLogStreamFailure($verdict, $snapshot);
        }
    }

    return $failures;
}

// ------------------------------------------------------------------ commands

/**
 * Run one command inside a container of the stand, through `sh -c`, and say how it went.
 *
 * @param string $container The container.
 * @param string $shell The shell line to run inside it.
 * @return array{ok: bool, output: string, note: string}
 */
function containerExec(string $container, string $shell): array
{
    $ran = runArtifactCommand(
        'docker',
        'docker exec ' . escapeshellarg($container) . ' sh -c ' . escapeshellarg($shell),
        sys_get_temp_dir(),
        LOG_STREAMS_COMMAND_TIMEOUT_SECONDS,
    );

    return ['ok' => $ran['missing'] === [], 'output' => $ran['output'], 'note' => implode('; ', $ran['missing'])];
}

/**
 * Run one command from the stand's directory, bounded, and say how it went.
 *
 * @param array{cwd: string} $box Where things are.
 * @param string $command A shell line.
 * @param int $timeoutSeconds How long it may take.
 * @return array{ok: bool, output: string, note: string}
 */
function runFromStand(array $box, string $command, int $timeoutSeconds): array
{
    $ran = runArtifactCommand('stand', $command, $box['cwd'], $timeoutSeconds);

    return ['ok' => $ran['missing'] === [], 'output' => $ran['output'], 'note' => implode('; ', $ran['missing'])];
}
