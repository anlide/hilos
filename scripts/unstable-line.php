<?php

declare(strict_types=1);

/**
 * The flaky half of a test run's report: what
 * `framework/frontend/scripts/unstable-reporter.mjs` printed, read back out of a
 * step's log and shaped for the two places `scripts/run-test-suite.php` shows it —
 * the rc ledger and the closing summary.
 *
 * The runner knows this grammar and nothing about Playwright's own formats,
 * because the same runner has to work in GitHub Actions and on a fresh checkout. A
 * step that never prints the line — phpunit, vitest, check — reports zero by
 * staying quiet, and so does a Playwright step whose tests all passed first time.
 *
 * Silence at zero is the rule this file exists to keep: no line means no field in
 * the ledger and no section in the summary, so a clean run looks byte for byte the
 * way it looked before the gate existed. A section that is printed every run
 * becomes background exactly the way Playwright's own "N flaky" already has.
 *
 * This file only declares functions and executes nothing, so that the runner and
 * `framework/tests/Unit/UnstableLineTest.php` can both require it.
 */

/** The one line a Playwright step prints about the tests that only passed on a retry. */
const UNSTABLE_LINE_PATTERN = '/^hilos-unstable: (\d+) \((.*)\)$/m';

/** How a reported list is split back into names, tolerating stray spacing. */
const UNSTABLE_NAME_SPLIT_PATTERN = '/\s*,\s*/';

/** How the summary joins the names of one step back together. */
const UNSTABLE_NAME_SEPARATOR = ', ';

/** The ledger field's name, appended after the entry every reader already parses. */
const UNSTABLE_LEDGER_KEY = 'unstable';

/**
 * The retried tests one step reported, read out of its captured output.
 *
 * A step may print the line more than once, so counts add up and lists concatenate
 * rather than the last line winning. The count is taken from the number the
 * reporter printed and the names from the text beside it, without reconciling the
 * two: whatever stood in the parentheses reaches the summary as itself, instead of
 * quietly changing how many flickers the run admits to.
 *
 * @param string $log ONE step's captured output. The run's own log will not do:
 *     the runner replays every step into it, so each line would be counted twice.
 * @return array{count: int, tests: array<int, string>}
 */
function readUnstableTests(string $log): array
{
    preg_match_all(UNSTABLE_LINE_PATTERN, $log, $lines, PREG_SET_ORDER);
    $count = 0;
    $tests = [];
    foreach ($lines as $line) {
        $count += (int)$line[1];
        $tests = [...$tests, ...unstableNames($line[2])];
    }

    return ['count' => $count, 'tests' => $tests];
}

/**
 * The names inside one line's parentheses, in the order the reporter listed them.
 *
 * @param string $list Whatever stood between the parentheses.
 * @return array<int, string>
 */
function unstableNames(string $list): array
{
    $names = preg_split(UNSTABLE_NAME_SPLIT_PATTERN, trim($list));
    if ($names === false) {
        return [];
    }

    return array_values(array_filter($names, static fn(string $name): bool => $name !== ''));
}

/**
 * The report of a step that has none — one that was skipped, or one whose command
 * never prints the line at all.
 *
 * @return array{count: int, tests: array<int, string>}
 */
function noUnstableTests(): array
{
    return ['count' => 0, 'tests' => []];
}

/**
 * What a step's ledger entry carries beyond `<id> rc=<n>`, which is nothing at all
 * when the step was clean — the existing grammar is what every reader still sees,
 * and a green run's ledger does not change.
 *
 * @param array{count: int} $unstable What the step reported.
 * @return string Either an empty string, or ` unstable=<n>` with its leading space.
 */
function unstableLedgerField(array $unstable): string
{
    if ($unstable['count'] === 0) {
        return '';
    }

    return ' ' . UNSTABLE_LEDGER_KEY . '=' . $unstable['count'];
}

/**
 * The summary's flaky section, printed after the run's closing counts, or nothing
 * at all when every step was clean.
 *
 * Steps that reported nothing are dropped rather than listed as zero: the section
 * exists to name what to go and look at, and a table of zeroes buries that.
 *
 * @param array<string, array{count: int, tests: array<int, string>}> $byStep What each
 *     planned step reported, keyed by step id, in the order the summary listed them.
 * @return string Whole lines, ready to write.
 */
function unstableSummarySection(array $byStep): string
{
    $reported = array_filter($byStep, static fn(array $unstable): bool => $unstable['count'] > 0);
    if ($reported === []) {
        return '';
    }

    $section = sprintf(
        "=== unstable: %d step(s), %d retried test(s) ===\n",
        count($reported),
        array_sum(array_column($reported, 'count')),
    );
    foreach ($reported as $id => $unstable) {
        $section .= sprintf("  %-20s %s\n", $id, implode(UNSTABLE_NAME_SEPARATOR, $unstable['tests']));
    }

    return $section;
}
