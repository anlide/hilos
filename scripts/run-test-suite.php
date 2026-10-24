<?php

declare(strict_types=1);

/**
 * Runs the test graph in `scripts/test-suite.php` with a bounded number of steps
 * at a time.
 *
 * The full run used to be a fixed sequence, and cost the sum of its parts even
 * though most of them do not touch each other: the cluster suite waits out grace
 * windows while the frontend checks burn CPU, and tasks plus polls
 * together fit inside chat's e2e with two minutes to spare (HIL-527). What makes
 * that safe rather than merely faster is the graph — its edges say which steps
 * write the same tree, and its groups say which share a compose project.
 *
 *   php scripts/run-test-suite.php                       the whole graph
 *   php scripts/run-test-suite.php frontend              a tag, plus its dependencies
 *   php scripts/run-test-suite.php chat-e2e --lanes=1    one step, alone
 *   php scripts/run-test-suite.php chat-e2e --lanes=1 --tests=tests/bots.spec.ts:71
 *                                                        one failed test of it, alone
 *   php scripts/run-test-suite.php --list                the plan, without running it
 *
 * Options:
 *   --lanes=N      steps at a time; overrides HILOS_TEST_LANES and the adaptive
 *                  default, which is sized from the cores and the available memory
 *                  of the machine (`scripts/lane-count.php`). `--lanes=1` gathers
 *                  diagnostic evidence alone. The full run owns the verdict;
 *                  a narrow green re-run does not replace a full red one.
 *                  Lane count is not a timeout multiplier: every suite reads its
 *                  factor from host load and memory at the moment it starts.
 *   --log-dir=DIR  where the per-step logs land (default `var/test-suite`). A full run
 *                  also sweeps out of it the logs of steps the manifest no longer
 *                  lists; a re-run of one step sweeps nothing.
 *   --rc-file=PATH where the `<id> rc=<n>` ledger lands (default `<log-dir>/rc`).
 *   --tests=LIST   the tests to run inside one e2e step, comma-separated in the form
 *                  Playwright takes on its command line (`tests/a.spec.ts:71`). Only
 *                  with exactly one target, and only a step that declares
 *                  `takesTests`: the re-run of a red e2e step names its red tests
 *                  rather than paying for the whole suite again.
 *   --repeat=N    a positive decimal integer within PHP int range. Requires
 *                  nonempty --tests and exactly one step id with takesTests.
 *                  Runs each selected test N times in one clean cycle; dependencies
 *                  run once, automatic retries stay unchanged, and there is no fail-fast.
 *   --artifact-dir=DIR
 *                  where each step's snapshot of the stand lands (default
 *                  `<log-dir>/artifacts`), one subdirectory per step. Taken at
 *                  every outcome, so that reading a red step starts from what it
 *                  left behind rather than from what the log seemed to say.
 *
 * Composer and hilos-ops/verify-run.sh drive the same repository-owned command
 * line. The runner also works on a fresh checkout without hilos-ops: its verdict
 * depends only on each command's exit code and the hilos-unstable evidence it prints.
 */

/** Poll interval while waiting for the running steps, in microseconds. */
const POLL_INTERVAL_MICROSECONDS = 200_000;

/** The option overriding both the environment and the adaptive lane count. */
const LANES_OPTION = '--lanes';

/** The option naming the tests to run inside one e2e step. */
const TESTS_OPTION = '--tests';

/** The option requesting this many observations of each selected test. */
const REPEAT_OPTION = '--repeat';

/** What separates the tests listed in one TESTS_OPTION value. */
const TESTS_SEPARATOR = ',';

/** Environment variable overriding the adaptive lane count. */
const LANES_VAR = 'HILOS_TEST_LANES';

/** Permissions for a log directory the runner has to create. */
const LOG_DIR_MODE = 0o755;

/** Bytes read at a time when a finished step's log is replayed to stdout. */
const LOG_REPLAY_CHUNK_BYTES = 65_536;

/** /proc/meminfo reports memory in kB; this converts that to GiB. */
const KIB_PER_GIB = 1024 * 1024;

/** Seconds in a minute. */
const SECONDS_PER_MINUTE = 60;

/** How a step ended. A step that never started is `Skipped`. */
enum StepOutcome: string
{
    case Ok = 'ok';
    case Failed = 'FAILED';
    case Skipped = 'skipped';
}

require_once __DIR__ . '/lane-count.php';
require_once __DIR__ . '/launch-order.php';
require_once __DIR__ . '/unstable-line.php';
require_once __DIR__ . '/step-artifacts.php';
require_once __DIR__ . '/stand-registry.php';
require_once __DIR__ . '/stand-teardown.php';
require_once __DIR__ . '/evidence-sweep.php';

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        exit(runTestSuite(array_slice($argv, 1)));
    } catch (InvalidArgumentException $exception) {
        fail($exception->getMessage());
    }
}

/**
 * @param array<int, string> $arguments Everything after the script name.
 * @return int The run's exit code, or zero for help and a printed plan.
 * @throws InvalidArgumentException When an option or its target is invalid.
 */
function runTestSuite(array $arguments): int
{
    $root = dirname(__DIR__);
    $options = parseArguments($arguments);
    if ($options['help']) {
        printUsage();

        return 0;
    }
    $manifest = narrowToTests(
        indexById(require $root . '/scripts/test-suite.php'),
        $options['targets'],
        $options['tests'],
        $options['repeat'],
    );
    $plan = planFor($manifest, $options['targets']);
    ['count' => $lanes, 'source' => $lanesSource] = resolveLanes($options['lanes']);
    $logDir = $options['logDir'] ?? $root . '/var/test-suite';

    if ($options['list']) {
        printPlan($manifest, $plan, $lanes, $lanesSource);

        return 0;
    }

    return runPlan(
        $root,
        $manifest,
        $plan,
        $lanes,
        $lanesSource,
        $logDir,
        $options['rcFile'] ?? $logDir . '/rc',
        $options['artifactDir'] ?? $logDir . '/artifacts',
        $options['targets'] === [],
    );
}

// ------------------------------------------------------------------ arguments

/**
 * Read the command line.
 *
 * @param array<int, string> $arguments Everything after the script name.
 * @return array{targets: array<int, string>, lanes: int|null, logDir: string|null,
 *     rcFile: string|null, artifactDir: string|null, list: bool, help: bool, tests: array<int, string>, repeat: int|null}
 *     Parsed options; repeat is null only when the option was absent.
 * @throws InvalidArgumentException When an option has an invalid value or is unknown.
 */
function parseArguments(array $arguments): array
{
    $parsed = [
        'targets' => [],
        'lanes' => null,
        'logDir' => null,
        'rcFile' => null,
        'artifactDir' => null,
        'list' => false,
        'help' => false,
        'tests' => [],
        'repeat' => null,
    ];
    $testList = null;
    foreach ($arguments as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $parsed['help'] = true;
        } elseif ($argument === '--list') {
            $parsed['list'] = true;
        } elseif (str_starts_with($argument, LANES_OPTION . '=')) {
            $parsed['lanes'] = readLaneCount(substr($argument, strlen(LANES_OPTION . '=')), LANES_OPTION);
        } elseif (str_starts_with($argument, TESTS_OPTION . '=')) {
            $testList = substr($argument, strlen(TESTS_OPTION . '='));
        } elseif (str_starts_with($argument, REPEAT_OPTION . '=')) {
            $parsed['repeat'] = readRepeatCount(substr($argument, strlen(REPEAT_OPTION . '=')));
        } elseif (str_starts_with($argument, '--log-dir=')) {
            $parsed['logDir'] = rtrim(substr($argument, strlen('--log-dir=')), '/');
        } elseif (str_starts_with($argument, '--rc-file=')) {
            $parsed['rcFile'] = substr($argument, strlen('--rc-file='));
        } elseif (str_starts_with($argument, '--artifact-dir=')) {
            $parsed['artifactDir'] = rtrim(substr($argument, strlen('--artifact-dir=')), '/');
        } elseif (str_starts_with($argument, '-')) {
            throw new InvalidArgumentException('unknown option ' . $argument);
        } else {
            $parsed['targets'][] = $argument;
        }
    }
    if ($testList !== null) {
        try {
            $parsed['tests'] = readTestList($testList);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                ($parsed['repeat'] === null ? '' : REPEAT_OPTION . ': ') . $exception->getMessage(),
            );
        }
    }

    return $parsed;
}

/**
 * @param string $value The decimal text after REPEAT_OPTION's equals sign.
 * @return int A positive count within PHP's integer range.
 * @throws InvalidArgumentException When the value is not a positive decimal integer or overflows.
 */
function readRepeatCount(string $value): int
{
    $digits = ltrim($value, '0');
    $maximum = (string)PHP_INT_MAX;
    if (preg_match('/\A[0-9]+\z/', $value) !== 1 || $digits === ''
        || strlen($digits) > strlen($maximum)
        || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
    ) {
        throw new InvalidArgumentException(REPEAT_OPTION . ' must be a positive integer within PHP int range, got ' . var_export($value, true));
    }

    return (int)$digits;
}

/**
 * A positive lane count, or a refusal naming where the bad value came from.
 *
 * @param string $value The raw text.
 * @param string $source What to blame in the message — an option or a variable.
 * @return int The requested lane count.
 * @throws InvalidArgumentException When the value is not positive decimal text.
 */
function readLaneCount(string $value, string $source): int
{
    if (preg_match('/^\d+$/', $value) !== 1 || (int)$value < 1) {
        throw new InvalidArgumentException($source . ' must be a positive integer, got ' . var_export($value, true));
    }

    return (int)$value;
}

/**
 * The tests a TESTS_OPTION value names, or a refusal when it names none.
 *
 * @param string $value The raw text after the `=`.
 * @return array<int, string> The nonempty test selectors.
 * @throws InvalidArgumentException When the option names no test.
 */
function readTestList(string $value): array
{
    $tests = array_values(array_filter(array_map(trim(...), explode(TESTS_SEPARATOR, $value)), static fn(string $test): bool => $test !== ''));
    if ($tests === []) {
        throw new InvalidArgumentException(TESTS_OPTION . ' names no test');
    }

    return $tests;
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
          --tests=LIST    tests inside the one e2e step targeted, comma-separated
                          as Playwright takes them (tests/a.spec.ts:71).
          --repeat=N      run each named test N times (positive decimal PHP int);
                          requires --tests and exactly one step id taking tests.
                          Dependencies run once; automatic retries stay unchanged.
          --log-dir=DIR   per-step logs (default var/test-suite).
          --rc-file=PATH  the `<id> rc=<n>` ledger (default <log-dir>/rc).
          --artifact-dir=DIR
                          per-step snapshots of the stand (default
                          <log-dir>/artifacts).
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
 * The manifest with the named tests handed to the one e2e step that runs them.
 *
 * The step declares `takesTests` when its command passes trailing arguments to
 * Playwright and to nothing else; the tests then reach the command after `--`.
 * Several targets would leave it unsaid whose tests they are, so exactly one is
 * accepted, and it must be such a step itself rather than a tag.
 *
 * @param array<string, array{command: string}> $manifest Steps by id.
 * @param array<int, string> $targets Step ids and tags from the command line.
 * @param array<int, string> $tests The tests TESTS_OPTION named; empty when it was not given.
 * @param int|null $repeat Observations per selected test; null leaves the command unchanged beyond its tests.
 * @return array<string, array{command: string}> The manifest with only the selected step's command narrowed.
 * @throws InvalidArgumentException When tests or repeat do not target exactly one capable step.
 */
function narrowToTests(array $manifest, array $targets, array $tests, ?int $repeat = null): array
{
    if ($repeat !== null && ($repeat < 1 || $tests === [])) {
        throw new InvalidArgumentException(REPEAT_OPTION . ' requires a positive count and nonempty ' . TESTS_OPTION);
    }
    if ($tests === []) {
        return $manifest;
    }
    $target = count($targets) === 1 ? $targets[0] : null;
    if ($target === null || ($manifest[$target]['takesTests'] ?? false) !== true) {
        throw new InvalidArgumentException(
            ($repeat === null ? TESTS_OPTION : REPEAT_OPTION)
                . ' names tests of one e2e step: give exactly one target, the id of a step that takes tests',
        );
    }
    $manifest[$target]['command'] .= ' -- ' . implode(' ', array_map(escapeshellarg(...), $tests));
    if ($repeat !== null) {
        $manifest[$target]['command'] .= ' --repeat-each=' . $repeat;
    }

    return $manifest;
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
 * Report the plan without running it: what would run, after what, at how many
 * lanes, and what that lane count came from.
 *
 * @param array<string, array{deps: array<int, string>, group: string|null, seconds: int}> $manifest Steps by id.
 * @param array<int, string> $plan Step ids to run.
 * @param int $lanes Steps at a time.
 * @param string $lanesSource What decided the lane count, as {@see resolveLanes()} worded it.
 */
function printPlan(array $manifest, array $plan, int $lanes, string $lanesSource): void
{
    fwrite(STDOUT, sprintf("plan: %d steps at %d lane(s)\n", count($plan), $lanes));
    fwrite(STDOUT, lanesLine($lanes, $lanesSource));
    foreach (launchOrder($manifest, $plan) as $id) {
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
 * How many steps may run at once, and what decided it: the explicit option, then
 * the environment, then a default sized from the machine by
 * {@see adaptiveLaneCount()}.
 *
 * The source travels with the number so that the run can print both: a lane count
 * the machine chose has to be explainable from the log, the same way a suite's
 * timeout factor is.
 *
 * @param int|null $override The `--lanes=` value, when one was given.
 * @return array{count: int, source: string} Steps at a time, and the words for what decided it.
 * @throws InvalidArgumentException When the lane-count environment variable is invalid.
 */
function resolveLanes(?int $override): array
{
    if ($override !== null) {
        return ['count' => $override, 'source' => LANES_OPTION];
    }

    $fromEnvironment = getenv(LANES_VAR);
    if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
        return ['count' => readLaneCount(trim($fromEnvironment), LANES_VAR), 'source' => LANES_VAR];
    }

    $cores = countCores();
    $available = readAvailableGib();

    return [
        'count' => adaptiveLaneCount($cores, $available),
        'source' => $cores === 0 || $available === null
            ? 'adaptive: the machine did not report its size'
            : sprintf('adaptive: %d cores, %.1f GiB available', $cores, $available),
    ];
}

/**
 * The line a run and a plan both print about their lane count.
 *
 * @param int $lanes Steps at a time.
 * @param string $source What decided that number, as {@see resolveLanes()} worded it.
 * @return string The line, newline included.
 */
function lanesLine(int $lanes, string $source): string
{
    return sprintf("lanes: %d (%s)\n", $lanes, $source);
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
        ? (int)$matched[1] / KIB_PER_GIB
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
 * @param string $lanesSource What decided the lane count, as {@see resolveLanes()} worded it.
 * @param string $logDir Directory for the per-step logs.
 * @param string $rcFile Path of the `<id> rc=<n>` ledger.
 * @param string $artifactDir Directory the per-step snapshots of the stand go under.
 * @param bool $fullRun Whether the plan is the whole manifest. It decides both of the
 *     tidyings the run does before its first step: taking every known stand down, which a
 *     re-run of one step does not need on a box a full run has just left clean, and
 *     sweeping the evidence of steps the manifest no longer lists, which is only sound
 *     when "not in the plan" reliably means "not there at all".
 * @return int
 */
function runPlan(
    string $root,
    array $manifest,
    array $plan,
    int $lanes,
    string $lanesSource,
    string $logDir,
    string $rcFile,
    string $artifactDir,
    bool $fullRun,
): int {
    $logs = prepareLogDir($logDir, $plan);
    prepareArtifactDir($artifactDir, $plan);
    if ($fullRun) {
        // Nothing is written here unless something was swept, so a clean box keeps the
        // text it has always had and the section showing up is itself the news.
        fwrite(STDOUT, sweptEvidenceSection(sweepStaleEvidence($logDir, $artifactDir, array_keys($manifest))));
        $survived = sweepStands($root);
        if ($survived !== []) {
            fwrite(STDERR, 'stands: run not started, these stands would not go down: '
                . implode(', ', $survived) . "\n");

            return 1;
        }
    }
    $rc = openLedger($rcFile);
    $startedAt = microtime(true);
    fwrite(STDOUT, lanesLine($lanes, $lanesSource));
    fwrite(STDOUT, sprintf("=== SUITE START %s — %d steps, %d lane(s) ===\n", now(), count($plan), $lanes));

    /** @var array<int, string> $pending Ids not started yet, in {@see launchOrder()}. */
    $pending = launchOrder($manifest, $plan);
    /** @var array<string, array{handle: resource, since: float, at: string,
     *     neighbors: array<int, string>}> $running */
    $running = [];
    /** @var array<string, array{path: string, reason: string, standCommand: string|null,
     *     missing: array<int, string>}> $artifacts Snapshots taken, by step id. */
    $artifacts = [];
    /** @var array<string, array{outcome: StepOutcome, rc: int, at: string, seconds: float, note: string,
     *     unstable: array{count: int, tests: array<int, string>}}> $done */
    $done = [];

    while ($pending !== [] || $running !== []) {
        foreach (launchable($manifest, $pending, $running, $done, $lanes) as $id) {
            $pending = array_values(array_diff($pending, [$id]));
            $running[$id] = launch($root, $manifest[$id], $logs[$id], array_keys($running));
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
            // Collected here rather than inside reap(), which knows neither the
            // step's working directory nor who it shared the box with.
            $context = [
                'lanes' => $lanes,
                'logPath' => $logs[$id],
                'neighborsAtStart' => $running[$id]['neighbors'],
                'neighborsAtFinish' => array_values(array_diff(array_keys($running), [$id])),
            ];
            unset($running[$id]);
            $done[$id] = $finished;
            $artifacts[$id] = collectStepArtifacts($root, $id, $manifest[$id], $finished, $context, $artifactDir);
            dropStandAfterStep($root, $manifest[$id]);
            reportFinish($id, $finished, $logs[$id], $rc, $artifacts[$id]);
        }
    }

    fwrite($rc, 'ALL DONE ' . now() . "\n");
    fclose($rc);

    return summarize($plan, $done, $artifacts, $lanes, microtime(true) - $startedAt, $logDir);
}

/**
 * Take every known stand down before a full run touches anything.
 *
 * Unconditional on purpose: it asks nothing, spares nothing, and a stand somebody raised by hand
 * goes down with the rest. The trade was made deliberately — a person is at the keyboard and can
 * raise theirs again, while a run that starts on a dirty box gets a false red sixteen minutes
 * later, which is then read as a defect in whatever ticket happened to be under it.
 *
 * @param string $root Repository root.
 * @return array<int, string> The ids of the stands something survived on; empty when the box came
 *     out clean.
 */
function sweepStands(string $root): array
{
    $survived = [];
    foreach (tearDownStands($root, standRegistry($root)) as $result) {
        fwrite(STDOUT, describeTeardown($result) . "\n");
        if ($result['problem'] !== '' || $result['residue']['containers'] !== [] || $result['residue']['networks'] !== []) {
            $survived[] = $result['id'];
        }
    }

    return $survived;
}

/**
 * Drop the stand a step declared it takes down with it, if it declared one.
 *
 * A cluster step does. This is hygiene: its fleet is three to five PHP daemons, a database
 * and a cli container with no reason to outlive the step, and a fleet forgotten for the rest of a
 * run once cost chat-e2e 16m10s against 9m36s plus fourteen failures that were nothing but the
 * leak (HIL-752). It is not what lets a fleet run beside an e2e step — they may overlap, as the
 * head of `scripts/test-suite.php` says. The demo stands cost nothing like that and deliberately
 * stay up: a red one gets climbed over by hand afterwards.
 *
 * The caller places this AFTER the step's snapshot was collected. Taking the stand down first
 * would leave the snapshot of a red step empty, which is the one case it exists for.
 *
 * Which stand comes from the step's `stand` key, the same one the snapshot resolved by; this
 * only decides WHETHER. The two questions are separate because twelve demo steps drive a stand
 * they deliberately do not drop, and answering both with one key would have taken them down.
 *
 * @param string $root Repository root.
 * @param array{id: string, stand?: string, downsStand?: bool} $step The manifest entry of the
 *     step that ended.
 */
function dropStandAfterStep(string $root, array $step): void
{
    if (($step['downsStand'] ?? false) === false) {
        return;
    }

    $wanted = $step['stand'] ?? null;
    if ($wanted === null) {
        fwrite(STDERR, 'stands: step ' . $step['id'] . ' drops a stand it never names' . "\n");

        return;
    }

    $stand = standById($root, $wanted);
    if ($stand === null) {
        fwrite(STDERR, 'stands: step ' . $step['id'] . ' names an unknown stand ' . $wanted . "\n");

        return;
    }
    fwrite(STDOUT, describeTeardown(tearDownStand($root, $stand)) . "\n");
}

/**
 * The ids that may start right now: dependencies green, group free, lane free.
 *
 * @param array<string, array{deps: array<int, string>, group: string|null}> $manifest Steps by id.
 * @param array<int, string> $pending Ids not started yet, in {@see launchOrder()}.
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
 * @param array<int, string> $neighbors Ids already running, kept beside the record so
 *     that the step's snapshot can name who it shared the box with.
 * @return array{handle: resource, since: float, at: string, neighbors: array<int, string>}
 */
function launch(string $root, array $step, string $logPath, array $neighbors): array
{
    $streams = [1 => ['file', $logPath, 'w'], 2 => ['redirect', 1]];
    $handle = proc_open($step['command'], $streams, $pipes, $root . '/' . $step['cwd']);
    if (!is_resource($handle)) {
        fail('could not start ' . $step['id']);
    }

    return ['handle' => $handle, 'since' => microtime(true), 'at' => now(), 'neighbors' => $neighbors];
}

/**
 * Whichever running steps have exited since the last look.
 *
 * A step's flaky tests are read here, together with the rest of its result: the
 * log is complete the moment the process is gone. The effective verdict is red
 * for a native failure or a retried success before dependencies, artifacts, END,
 * the ledger and the summary consume the result.
 *
 * @param array<string, array{handle: resource, since: float, at: string,
 *     neighbors: array<int, string>}> $running Ids in flight.
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
        $finished[$id] = finishedStep(
            $status['exitcode'],
            readStepLog($logs[$id]),
            $step['at'],
            microtime(true) - $step['since'],
        );
    }

    return $finished;
}

/**
 * Classify a completed command before publishing it to dependencies and reports.
 *
 * @param int $processRc The command's own exit code.
 * @param string $log The complete output of this step only.
 * @param string $at The step's start time.
 * @param float $seconds The step's elapsed duration.
 * @return array{outcome: StepOutcome, rc: int, at: string, seconds: float, note: string,
 *     unstable: array{count: int, tests: array<int, string>}} The effective verdict and its evidence.
 */
function finishedStep(int $processRc, string $log, string $at, float $seconds): array
{
    $unstable = readUnstableTests($log);
    $rc = effectiveStepRc($processRc, $unstable);

    return [
        'outcome' => $rc === 0 ? StepOutcome::Ok : StepOutcome::Failed,
        'rc' => $rc,
        'at' => $at,
        'seconds' => $seconds,
        'note' => '',
        'unstable' => $unstable,
    ];
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
 * @param array{path: string, reason: string, standCommand: string|null} $artifacts Where the
 *     step's snapshot of the stand went.
 */
function reportFinish(string $id, array $finished, string $logPath, $rc, array $artifacts): void
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
    // Straight under the verdict, because that is where the reader already is, and
    // the stand it points at is about to be torn down by whatever runs next.
    fwrite(STDOUT, artifactPointerLine($id, $artifacts) . "\n");
    fwrite($rc, stepLedgerLine($id, $finished));
}

/**
 * @param string $id The completed step's id.
 * @param array{rc: int, unstable: array{count: int}} $finished The effective result.
 * @return string The existing ledger grammar with retry evidence only when present.
 */
function stepLedgerLine(string $id, array $finished): string
{
    return sprintf("%s rc=%d%s\n", $id, $finished['rc'], unstableLedgerField($finished['unstable']));
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
 * @param array<string, array{path: string, reason: string}> $artifacts Snapshots taken, by id.
 *     A skipped step has none: it never ran, so there was nothing of it on the stand.
 * @param int $lanes Steps at a time.
 * @param float $wall Seconds the whole run took.
 * @param string $logDir Where the per-step logs are.
 * @return int
 */
function summarize(array $plan, array $done, array $artifacts, int $lanes, float $wall, string $logDir): int
{
    $report = suiteSummary($plan, $done, $artifacts, $lanes, $wall, $logDir, now());
    fwrite(STDOUT, $report['text']);

    return $report['rc'];
}

/**
 * Format the closing table with the same verdict the scheduler already consumed.
 *
 * @param array<int, string> $plan Step ids that were planned, in manifest order.
 * @param array<string, array{outcome: StepOutcome, seconds: float, note: string,
 *     unstable: array{count: int, tests: array<int, string>}}> $done Results by id.
 * @param array<string, array{path: string, reason: string}> $artifacts Snapshots taken, by id.
 *     A skipped step has none: it never ran, so there was nothing of it on the stand.
 * @param int $lanes Steps at a time.
 * @param float $wall Seconds the whole run took.
 * @param string $logDir Where the per-step logs are.
 * @param string $at The summary timestamp.
 * @return array{rc: int, text: string} The run verdict and the complete closing report.
 */
function suiteSummary(array $plan, array $done, array $artifacts, int $lanes, float $wall, string $logDir, string $at): array
{
    $text = sprintf("=== SUMMARY %s — %s at %d lane(s) ===\n", $at, formatDuration($wall), $lanes);
    $red = 0;
    $notRun = 0;
    $unstable = [];
    $collected = [];
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
        if (isset($artifacts[$id])) {
            $collected[$id] = $artifacts[$id];
        }
        $text .= sprintf(
            "  %-8s %-20s %8s  %s\n",
            $result['outcome']->value,
            $id,
            $isSkip ? '-' : formatDuration($result['seconds']),
            $isSkip ? 'blocked by ' . $result['note'] : $logDir . '/' . $id . '.log',
        );
    }
    $text .= sprintf(
        "=== %d ok, %d failed, %d skipped — logs in %s ===\n",
        count($plan) - $red - $notRun,
        $red,
        $notRun,
        $logDir,
    );
    $text .= artifactSummarySection($collected);
    // A run with nothing to report writes nothing here, so a green log is the same
    // text it has always been and the section showing up is itself the news.
    $text .= unstableSummarySection($unstable);

    return ['rc' => $red + $notRun === 0 ? 0 : 1, 'text' => $text];
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
 * Create the artifact directory if needed and drop this plan's previous snapshots.
 *
 * The same rule the logs keep: only the planned ids are removed, so re-running one
 * step leaves the rest of the previous run's evidence readable beside it. Failing
 * here is a fatal configuration error rather than a line inside a snapshot, because
 * it happens before any step has run and so cannot color anybody's verdict.
 *
 * @param string $artifactDir Directory the per-step snapshots go under.
 * @param array<int, string> $plan Step ids to run.
 */
function prepareArtifactDir(string $artifactDir, array $plan): void
{
    if (!is_dir($artifactDir) && !mkdir($artifactDir, ARTIFACT_DIR_MODE, true) && !is_dir($artifactDir)) {
        fail('could not create the artifact directory ' . $artifactDir);
    }
    foreach ($plan as $id) {
        removeArtifactTree($artifactDir . '/' . $id);
    }
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
    if ($whole < SECONDS_PER_MINUTE) {
        return $whole . 's';
    }

    return intdiv($whole, SECONDS_PER_MINUTE) . 'm' . str_pad((string)($whole % SECONDS_PER_MINUTE), 2, '0', STR_PAD_LEFT) . 's';
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
