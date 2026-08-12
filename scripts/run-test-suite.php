<?php

declare(strict_types=1);

/**
 * Runs the test graph in `scripts/test-suite.php` with a bounded number of steps
 * at a time.
 *
 * The full run used to be a fixed sequence, and cost the sum of its parts even
 * though most of them do not touch each other: the cluster suite waits out grace
 * windows while the frontend checks burn CPU, and simple-todo plus simple-poll
 * together fit inside chat's e2e with two minutes to spare (HIL-527). What makes
 * that safe rather than merely faster is the graph — its edges say which steps
 * write the same tree, and its groups say which share a compose project.
 *
 *   php scripts/run-test-suite.php                       the whole graph
 *   php scripts/run-test-suite.php frontend              a tag, plus its dependencies
 *   php scripts/run-test-suite.php chat-e2e --lanes=1    one step, alone
 *   php scripts/run-test-suite.php --list                the plan, without running it
 *
 * Options:
 *   --lanes=N      steps at a time; overrides HILOS_TEST_LANES and the adaptive
 *                  default. `--lanes=1` reproduces the old serial run exactly, and
 *                  is the lever the attribution rule needs: a step that goes red
 *                  under concurrency is not a verdict until it has been re-run
 *                  alone on the same HEAD.
 *   --log-dir=DIR  where the per-step logs land (default `var/test-suite`).
 *   --rc-file=PATH where the `<id> rc=<n>` ledger lands (default `<log-dir>/rc`).
 *
 * This file knows nothing about hilos-ops or about any particular CI: composer,
 * GitHub Actions and hilos-ops/verify-run.sh all drive it through the same command
 * line, and a dependency in the other direction would mean the suite cannot run on
 * a fresh checkout.
 */

/** Poll interval while waiting for the running steps, in microseconds. */
const POLL_INTERVAL_MICROSECONDS = 200_000;

/** Cores a machine needs before the adaptive default risks a second lane. */
const ADAPTIVE_LANES_MIN_CORES = 8;

/** Memory, in GiB, a machine needs free before the adaptive default risks a second lane. */
const ADAPTIVE_LANES_MIN_AVAILABLE_GIB = 4.0;

/** Lanes to use on a machine too small, or too unfamiliar, to measure. */
const SERIAL_LANES = 1;

/** Lanes the adaptive default settles on for a machine that is big enough. */
const PARALLEL_LANES = 2;

/** Environment variable overriding the adaptive lane count. */
const LANES_VAR = 'HILOS_TEST_LANES';

/** Permissions for a log directory the runner has to create. */
const LOG_DIR_MODE = 0o755;

/** Bytes read at a time when a finished step's log is replayed to stdout. */
const LOG_REPLAY_CHUNK_BYTES = 65_536;

/** How a step ended. A step that never started is `Skipped`. */
enum StepOutcome: string
{
    case Ok = 'ok';
    case Failed = 'FAILED';
    case Skipped = 'skipped';
}

$root = dirname(__DIR__);
require_once $root . '/scripts/unstable-line.php';
$options = parseArguments(array_slice($argv, 1));
$manifest = indexById(require $root . '/scripts/test-suite.php');
$plan = planFor($manifest, $options['targets']);
$lanes = resolveLanes($options['lanes']);
$logDir = $options['logDir'] ?? $root . '/var/test-suite';

if ($options['list']) {
    printPlan($manifest, $plan, $lanes);
    exit(0);
}

exit(runPlan($root, $manifest, $plan, $lanes, $logDir, $options['rcFile'] ?? $logDir . '/rc'));

// ------------------------------------------------------------------ arguments

/**
 * Read the command line.
 *
 * @param array<int, string> $arguments Everything after the script name.
 * @return array{targets: array<int, string>, lanes: int|null, logDir: string|null,
 *     rcFile: string|null, list: bool}
 */
function parseArguments(array $arguments): array
{
    $parsed = ['targets' => [], 'lanes' => null, 'logDir' => null, 'rcFile' => null, 'list' => false];
    foreach ($arguments as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            printUsage();
            exit(0);
        } elseif ($argument === '--list') {
            $parsed['list'] = true;
        } elseif (str_starts_with($argument, '--lanes=')) {
            $parsed['lanes'] = readLaneCount(substr($argument, strlen('--lanes=')), '--lanes');
        } elseif (str_starts_with($argument, '--log-dir=')) {
            $parsed['logDir'] = rtrim(substr($argument, strlen('--log-dir=')), '/');
        } elseif (str_starts_with($argument, '--rc-file=')) {
            $parsed['rcFile'] = substr($argument, strlen('--rc-file='));
        } elseif (str_starts_with($argument, '-')) {
            fail('unknown option ' . $argument);
        } else {
            $parsed['targets'][] = $argument;
        }
    }

    return $parsed;
}

/**
 * A positive lane count, or a fatal error naming where the bad value came from.
 *
 * @param string $value The raw text.
 * @param string $source What to blame in the message — an option or a variable.
 * @return int
 */
function readLaneCount(string $value, string $source): int
{
    if (preg_match('/^\d+$/', $value) !== 1 || (int)$value < 1) {
        fail($source . ' must be a positive integer, got ' . var_export($value, true));
    }

    return (int)$value;
}

/** Print how to call this script. */
function printUsage(): void
{
    fwrite(STDOUT, <<<'USAGE'
        usage: php scripts/run-test-suite.php [options] [target ...]

          target          a step id or a tag from scripts/test-suite.php; the
                          dependencies of whatever matches are pulled in. No
                          target at all runs the whole graph.
          --lanes=N       steps at a time (overrides HILOS_TEST_LANES).
          --log-dir=DIR   per-step logs (default var/test-suite).
          --rc-file=PATH  the `<id> rc=<n>` ledger (default <log-dir>/rc).
          --list          print the plan and exit.

        USAGE);
}

// ----------------------------------------------------------------------- plan

/**
 * The manifest keyed by step id, with its edges checked.
 *
 * @param array<int, array{id: string, command: string, cwd: string, deps: array<int, string>,
 *     group: string|null, tags: array<int, string>, seconds: int}> $steps The manifest as written.
 * @return array<string, array{id: string, command: string, cwd: string, deps: array<int, string>,
 *     group: string|null, tags: array<int, string>, seconds: int}>
 */
function indexById(array $steps): array
{
    $byId = [];
    foreach ($steps as $step) {
        if (isset($byId[$step['id']])) {
            fail('duplicate step id ' . $step['id']);
        }
        $byId[$step['id']] = $step;
    }
    foreach ($byId as $step) {
        foreach ($step['deps'] as $dependency) {
            if (!isset($byId[$dependency])) {
                fail($step['id'] . ' depends on unknown step ' . $dependency);
            }
        }
    }

    return $byId;
}

/**
 * The step ids to run, in manifest order: whatever the targets matched, plus
 * everything those steps transitively depend on.
 *
 * @param array<string, array{deps: array<int, string>, tags: array<int, string>}> $manifest Steps by id.
 * @param array<int, string> $targets Step ids and tags; empty means the whole graph.
 * @return array<int, string>
 */
function planFor(array $manifest, array $targets): array
{
    $wanted = $targets === [] ? array_keys($manifest) : [];
    foreach ($targets as $target) {
        $matched = array_keys(array_filter(
            $manifest,
            static fn(array $step, string $id): bool => $id === $target || in_array($target, $step['tags'], true),
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($matched === []) {
            fail('no step id or tag matches ' . var_export($target, true));
        }
        $wanted = [...$wanted, ...$matched];
    }

    $closed = [];
    while ($wanted !== []) {
        $id = array_pop($wanted);
        if (isset($closed[$id])) {
            continue;
        }
        $closed[$id] = true;
        $wanted = [...$wanted, ...$manifest[$id]['deps']];
    }

    return array_values(array_filter(array_keys($manifest), static fn(string $id): bool => isset($closed[$id])));
}

/**
 * Report the plan without running it: what would run, after what, and at how many
 * lanes.
 *
 * @param array<string, array{deps: array<int, string>, group: string|null, seconds: int}> $manifest Steps by id.
 * @param array<int, string> $plan Step ids to run.
 * @param int $lanes Steps at a time.
 */
function printPlan(array $manifest, array $plan, int $lanes): void
{
    fwrite(STDOUT, sprintf("plan: %d steps at %d lane(s)\n", count($plan), $lanes));
    foreach (sortByDurationDescending($manifest, $plan) as $id) {
        $step = $manifest[$id];
        fwrite(STDOUT, sprintf(
            "  %-20s %4ds  after [%s]%s\n",
            $id,
            $step['seconds'],
            implode(' ', $step['deps']),
            $step['group'] === null ? '' : '  group ' . $step['group'],
        ));
    }
}

// ---------------------------------------------------------------------- lanes

/**
 * How many steps may run at once: the explicit option, then the environment, then
 * a default derived from the machine.
 *
 * The default is deliberately shy. A 2-core GitHub runner has to degrade to the
 * serial run rather than suffocate, and a box that cannot report its own size is
 * treated as small — being slow is recoverable, thrashing a runner is not.
 *
 * @param int|null $override The `--lanes=` value, when one was given.
 * @return int
 */
function resolveLanes(?int $override): int
{
    if ($override !== null) {
        return $override;
    }

    $fromEnvironment = getenv(LANES_VAR);
    if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
        return readLaneCount(trim($fromEnvironment), LANES_VAR);
    }

    $available = readAvailableGib();
    if (countCores() < ADAPTIVE_LANES_MIN_CORES) {
        return SERIAL_LANES;
    }
    if ($available === null || $available < ADAPTIVE_LANES_MIN_AVAILABLE_GIB) {
        return SERIAL_LANES;
    }

    return PARALLEL_LANES;
}

/**
 * Cores this machine reports, or zero when it does not say.
 *
 * @return int
 */
function countCores(): int
{
    $cpuinfo = readProcFile('/proc/cpuinfo');

    return $cpuinfo === null ? 0 : preg_match_all('/^processor\s*:/m', $cpuinfo);
}

/**
 * MemAvailable in GiB, or null when this machine does not report it.
 *
 * @return float|null
 */
function readAvailableGib(): ?float
{
    $meminfo = readProcFile('/proc/meminfo');
    if ($meminfo === null) {
        return null;
    }

    return preg_match('/^MemAvailable:\s+(\d+) kB$/m', $meminfo, $matched) === 1
        ? (int)$matched[1] / (1024 * 1024)
        : null;
}

/**
 * A /proc file's contents, or null on any platform that does not have it. Absence
 * is the normal answer here, not an error, so it is checked rather than suppressed.
 *
 * @param string $path The file to read.
 * @return string|null
 */
function readProcFile(string $path): ?string
{
    if (!is_readable($path)) {
        return null;
    }
    $contents = file_get_contents($path);

    return $contents === false ? null : $contents;
}

// -------------------------------------------------------------------- running

/**
 * Run the plan and report. Returns the process exit code: zero only when every
 * planned step ran and every one of them was green.
 *
 * There is no fail-fast. A red step skips what depends on it and nothing else:
 * killing healthy neighbours mid-flight leaves live compose projects behind and
 * destroys the very picture a full run exists to produce.
 *
 * @param string $root Repository root; every step's `cwd` is relative to it.
 * @param array<string, array{id: string, command: string, cwd: string, deps: array<int, string>,
 *     group: string|null, seconds: int}> $manifest Steps by id.
 * @param array<int, string> $plan Step ids to run, in manifest order.
 * @param int $lanes Steps at a time.
 * @param string $logDir Directory for the per-step logs.
 * @param string $rcFile Path of the `<id> rc=<n>` ledger.
 * @return int
 */
function runPlan(string $root, array $manifest, array $plan, int $lanes, string $logDir, string $rcFile): int
{
    $logs = prepareLogDir($logDir, $plan);
    $rc = openLedger($rcFile);
    $startedAt = microtime(true);
    fwrite(STDOUT, sprintf("=== SUITE START %s — %d steps, %d lane(s) ===\n", now(), count($plan), $lanes));

    /** @var array<int, string> $pending Ids not started yet, longest first. */
    $pending = sortByDurationDescending($manifest, $plan);
    /** @var array<string, array{handle: resource, since: float, at: string}> $running */
    $running = [];
    /** @var array<string, array{outcome: StepOutcome, rc: int, at: string, seconds: float, note: string,
     *     unstable: array{count: int, tests: array<int, string>}}> $done */
    $done = [];

    while ($pending !== [] || $running !== []) {
        foreach (launchable($manifest, $pending, $running, $done, $lanes) as $id) {
            $pending = array_values(array_diff($pending, [$id]));
            $running[$id] = launch($root, $manifest[$id], $logs[$id]);
            fwrite(STDOUT, sprintf(">>> launched %s at %s (%d running)\n", $id, $running[$id]['at'], count($running)));
        }
        if ($running === []) {
            // Nothing runs and nothing can start, so nothing will change: every
            // step still pending is blocked by one that failed or was skipped.
            foreach ($pending as $id) {
                $done[$id] = skipped($manifest[$id], $done);
                reportSkip($id, $done[$id], $rc);
            }
            break;
        }
        usleep(POLL_INTERVAL_MICROSECONDS);
        foreach (reap($running, $logs) as $id => $finished) {
            unset($running[$id]);
            $done[$id] = $finished;
            reportFinish($id, $finished, $logs[$id], $rc);
        }
    }

    fwrite($rc, 'ALL DONE ' . now() . "\n");
    fclose($rc);

    return summarize($plan, $done, $lanes, microtime(true) - $startedAt, $logDir);
}

/**
 * Plan ids ordered longest-first, so that the tail of the run is short.
 *
 * @param array<string, array{seconds: int}> $manifest Steps by id.
 * @param array<int, string> $plan Step ids to run.
 * @return array<int, string>
 */
function sortByDurationDescending(array $manifest, array $plan): array
{
    usort($plan, static fn(string $a, string $b): int => $manifest[$b]['seconds'] <=> $manifest[$a]['seconds']);

    return $plan;
}

/**
 * The ids that may start right now: dependencies green, group free, lane free.
 *
 * @param array<string, array{deps: array<int, string>, group: string|null}> $manifest Steps by id.
 * @param array<int, string> $pending Ids not started yet, longest first.
 * @param array<string, array{handle: resource}> $running Ids in flight.
 * @param array<string, array{outcome: StepOutcome}> $done Ids already finished.
 * @param int $lanes Steps at a time.
 * @return array<int, string>
 */
function launchable(array $manifest, array $pending, array $running, array $done, int $lanes): array
{
    $free = $lanes - count($running);
    $busyGroups = [];
    foreach (array_keys($running) as $id) {
        if ($manifest[$id]['group'] !== null) {
            $busyGroups[$manifest[$id]['group']] = true;
        }
    }

    $starting = [];
    foreach ($pending as $id) {
        if ($free <= 0) {
            break;
        }
        $step = $manifest[$id];
        if ($step['group'] !== null && isset($busyGroups[$step['group']])) {
            continue;
        }
        if (!dependenciesMet($step['deps'], $done)) {
            continue;
        }
        if ($step['group'] !== null) {
            $busyGroups[$step['group']] = true;
        }
        $starting[] = $id;
        $free--;
    }

    return $starting;
}

/**
 * Whether every dependency has already finished green.
 *
 * @param array<int, string> $deps The step's dependencies.
 * @param array<string, array{outcome: StepOutcome}> $done Ids already finished.
 * @return bool
 */
function dependenciesMet(array $deps, array $done): bool
{
    foreach ($deps as $dependency) {
        if (($done[$dependency]['outcome'] ?? null) !== StepOutcome::Ok) {
            return false;
        }
    }

    return true;
}

/**
 * Start one step with both its streams redirected to its own file.
 *
 * Per-step files rather than pipes: two docker chains writing one stream produce
 * output nobody can attribute, and a runner that reads pipes has to keep draining
 * them or deadlock on a full buffer.
 *
 * @param string $root Repository root.
 * @param array{id: string, command: string, cwd: string} $step The step to start.
 * @param string $logPath Where its output goes.
 * @return array{handle: resource, since: float, at: string}
 */
function launch(string $root, array $step, string $logPath): array
{
    $streams = [1 => ['file', $logPath, 'w'], 2 => ['redirect', 1]];
    $handle = proc_open($step['command'], $streams, $pipes, $root . '/' . $step['cwd']);
    if (!is_resource($handle)) {
        fail('could not start ' . $step['id']);
    }

    return ['handle' => $handle, 'since' => microtime(true), 'at' => now()];
}

/**
 * Whichever running steps have exited since the last look.
 *
 * A step's flaky tests are read here, together with the rest of its result: the
 * log is complete the moment the process is gone, and every consumer downstream —
 * the ledger, the summary — then gets a record that is whole.
 *
 * @param array<string, array{handle: resource, since: float, at: string}> $running Ids in flight.
 * @param array<string, string> $logs Log path by step id.
 * @return array<string, array{outcome: StepOutcome, rc: int, at: string, seconds: float, note: string,
 *     unstable: array{count: int, tests: array<int, string>}}>
 */
function reap(array $running, array $logs): array
{
    $finished = [];
    foreach ($running as $id => $step) {
        $status = proc_get_status($step['handle']);
        if ($status['running']) {
            continue;
        }
        proc_close($step['handle']);
        $finished[$id] = [
            'outcome' => $status['exitcode'] === 0 ? StepOutcome::Ok : StepOutcome::Failed,
            'rc' => $status['exitcode'],
            'at' => $step['at'],
            'seconds' => microtime(true) - $step['since'],
            'note' => '',
            'unstable' => readUnstableTests(readStepLog($logs[$id])),
        ];
    }

    return $finished;
}

/**
 * A finished step's captured output, or an empty string when there is none to
 * read. Absence is the answer, not an error: a step that left no log reported no
 * flaky tests either, which is what a missing file already means here.
 *
 * @param string $logPath The step's output file.
 * @return string
 */
function readStepLog(string $logPath): string
{
    if (!is_readable($logPath)) {
        return '';
    }
    $contents = file_get_contents($logPath);
    if ($contents === false) {
        return '';
    }

    return $contents;
}

/**
 * The record for a step that never ran, naming the dependency that stopped it.
 *
 * @param array{deps: array<int, string>} $step The step being skipped.
 * @param array<string, array{outcome: StepOutcome}> $done Ids already finished.
 * @return array{outcome: StepOutcome, rc: int, at: string, seconds: float, note: string}
 */
function skipped(array $step, array $done): array
{
    $blocker = 'a dependency that never ran';
    foreach ($step['deps'] as $dependency) {
        $outcome = $done[$dependency]['outcome'] ?? null;
        if ($outcome !== StepOutcome::Ok) {
            $blocker = $dependency . ' ' . ($outcome?->value ?? 'never ran');
        }
    }

    return [
        'outcome' => StepOutcome::Skipped,
        'rc' => 0,
        'at' => now(),
        'seconds' => 0.0,
        'note' => $blocker,
        'unstable' => noUnstableTests(),
    ];
}

/**
 * Put a finished step into the run log and the ledger: its whole output between a
 * START and an END line, the same shape the serial runner produced.
 *
 * Concurrency is why the output is replayed here rather than streamed as it
 * happens — interleaved docker chains cannot be read line by line, and line by
 * line is how the playbook decides whose red it is.
 *
 * @param string $id The step that finished.
 * @param array{rc: int, at: string, seconds: float,
 *     unstable: array{count: int, tests: array<int, string>}} $finished How it went.
 * @param string $logPath Its output file.
 * @param resource $rc The ledger.
 */
function reportFinish(string $id, array $finished, string $logPath, $rc): void
{
    fwrite(STDOUT, sprintf("=== START %s %s ===\n", $id, $finished['at']));
    replay($logPath);
    fwrite(STDOUT, sprintf(
        "=== END %s rc=%d %s (%s) ===\n",
        $id,
        $finished['rc'],
        now(),
        formatDuration($finished['seconds']),
    ));
    fwrite($rc, sprintf("%s rc=%d%s\n", $id, $finished['rc'], unstableLedgerField($finished['unstable'])));
}

/**
 * Copy a step's log to stdout, ending on a newline whatever the step left behind,
 * so the closing END line always starts a line of its own.
 *
 * Chunked by hand rather than with `stream_copy_to_stream`, which refuses STDOUT
 * and reports the refusal only by returning false.
 *
 * @param string $logPath The file to replay.
 */
function replay(string $logPath): void
{
    $log = fopen($logPath, 'r');
    if (!is_resource($log)) {
        fwrite(STDOUT, '(no output was captured in ' . $logPath . ")\n");

        return;
    }
    $tail = "\n";
    while (!feof($log)) {
        $chunk = fread($log, LOG_REPLAY_CHUNK_BYTES);
        if ($chunk === false || $chunk === '') {
            break;
        }
        fwrite(STDOUT, $chunk);
        $tail = substr($chunk, -1);
    }
    fclose($log);
    if ($tail !== "\n") {
        fwrite(STDOUT, "\n");
    }
}

/**
 * Put a skipped step into the run log and the ledger. It gets no `rc=`, because
 * writing one would let a `grep rc=` sweep read it as a step that passed.
 *
 * @param string $id The step that never ran.
 * @param array{note: string} $skipped Why not.
 * @param resource $rc The ledger.
 */
function reportSkip(string $id, array $skipped, $rc): void
{
    fwrite(STDOUT, sprintf("=== SKIP %s (%s) ===\n", $id, $skipped['note']));
    fwrite($rc, sprintf("%s SKIPPED (%s)\n", $id, $skipped['note']));
}

/**
 * Print the closing table and return the exit code for the whole run.
 *
 * @param array<int, string> $plan Step ids that were planned, in manifest order.
 * @param array<string, array{outcome: StepOutcome, seconds: float, note: string,
 *     unstable: array{count: int, tests: array<int, string>}}> $done Results by id.
 * @param int $lanes Steps at a time.
 * @param float $wall Seconds the whole run took.
 * @param string $logDir Where the per-step logs are.
 * @return int
 */
function summarize(array $plan, array $done, int $lanes, float $wall, string $logDir): int
{
    fwrite(STDOUT, sprintf("=== SUMMARY %s — %s at %d lane(s) ===\n", now(), formatDuration($wall), $lanes));
    $red = 0;
    $notRun = 0;
    $unstable = [];
    foreach ($plan as $id) {
        $result = $done[$id];
        $isSkip = $result['outcome'] === StepOutcome::Skipped;
        if ($result['outcome'] === StepOutcome::Failed) {
            $red++;
        }
        if ($isSkip) {
            $notRun++;
        }
        $unstable[$id] = $result['unstable'];
        fwrite(STDOUT, sprintf(
            "  %-8s %-20s %8s  %s\n",
            $result['outcome']->value,
            $id,
            $isSkip ? '-' : formatDuration($result['seconds']),
            $isSkip ? 'blocked by ' . $result['note'] : $logDir . '/' . $id . '.log',
        ));
    }
    fwrite(STDOUT, sprintf(
        "=== %d ok, %d failed, %d skipped — logs in %s ===\n",
        count($plan) - $red - $notRun,
        $red,
        $notRun,
        $logDir,
    ));
    // A run with nothing to report writes nothing here, so a green log is the same
    // text it has always been and the section showing up is itself the news.
    fwrite(STDOUT, unstableSummarySection($unstable));

    return $red + $notRun === 0 ? 0 : 1;
}

// ------------------------------------------------------------------------- io

/**
 * Create the log directory if needed, drop this plan's previous logs, and hand
 * back the path per step. Only the planned ids are removed, so re-running one step
 * leaves the rest of the previous run's logs readable next to it.
 *
 * @param string $logDir Directory for the per-step logs.
 * @param array<int, string> $plan Step ids to run.
 * @return array<string, string> Log path by step id.
 */
function prepareLogDir(string $logDir, array $plan): array
{
    if (!is_dir($logDir) && !mkdir($logDir, LOG_DIR_MODE, true) && !is_dir($logDir)) {
        fail('could not create the log directory ' . $logDir);
    }
    $paths = [];
    foreach ($plan as $id) {
        $paths[$id] = $logDir . '/' . $id . '.log';
        if (is_file($paths[$id])) {
            unlink($paths[$id]);
        }
    }

    return $paths;
}

/**
 * Open the rc ledger, truncated: it describes this run and no earlier one.
 *
 * @param string $rcFile Path of the ledger.
 * @return resource
 */
function openLedger(string $rcFile)
{
    $handle = fopen($rcFile, 'w');
    if (!is_resource($handle)) {
        fail('could not write the rc file ' . $rcFile);
    }

    return $handle;
}

/**
 * The current time in the same ISO form the serial runner printed.
 *
 * @return string
 */
function now(): string
{
    return date('c');
}

/**
 * A duration as `12m15s`, or as `9s` when it is under a minute.
 *
 * @param float $seconds The duration.
 * @return string
 */
function formatDuration(float $seconds): string
{
    $whole = (int)round($seconds);
    if ($whole < 60) {
        return $whole . 's';
    }

    return intdiv($whole, 60) . 'm' . str_pad((string)($whole % 60), 2, '0', STR_PAD_LEFT) . 's';
}

/**
 * Report a mistake in how the runner was called or configured, and stop. This is
 * never a test failure: exit code 2 keeps it apart from a red run.
 *
 * @param string $message What is wrong.
 * @return never
 */
function fail(string $message): never
{
    fwrite(STDERR, 'run-test-suite: ' . $message . "\n");
    exit(2);
}
