<?php

declare(strict_types=1);

/**
 * Quarantine observations are report data, independent of the step's verdict.
 * Only a completed step's own log is read; replayed suite output is not input.
 * This file declares functions without starting the runner or loading the app.
 */

/** The producer's literal prefix must start a line, without summary indentation. */
const QUARANTINE_LINE_PATTERN = '/^hilos-quarantine: (.+)$/m';

/** Flake identifiers share the producer's grammar, without a separate registry. */
const QUARANTINE_FLAKE_PATTERN = '/\AF-[0-9]{3,}\z/';

/** Each valid status names its report counter; a pass needs no extra counter. */
const QUARANTINE_STATUS_COUNTERS = [
    'passed' => null,
    'failed' => 'failed',
    'timedOut' => 'failed',
    'skipped' => 'notRun',
    'interrupted' => 'notRun',
    'notRun' => 'notRun',
];

/** Keep quotes, newlines and Unicode safe on one summary line. */
const QUARANTINE_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/**
 * Malformed lines contribute no observation. JSON objects stay distinct from
 * lists while validating titlePath, including objects with numeric property names.
 *
 * @param string $log The complete captured output of one step, never the suite replay.
 * @return array{count: int, failed: int, notRun: int, tests: list<array{
 *     flake: string, status: string, file: string, line: int, project: string,
 *     titlePath: list<string>, reason: string}>} Valid observations in report order.
 */
function readQuarantineTests(string $log): array
{
    preg_match_all(QUARANTINE_LINE_PATTERN, $log, $lines, PREG_SET_ORDER);
    $quarantine = noQuarantineTests();
    foreach ($lines as $line) {
        $observation = json_decode($line[1]);
        if (!$observation instanceof stdClass
            || !isset(
                $observation->flake,
                $observation->status,
                $observation->file,
                $observation->line,
                $observation->project,
                $observation->titlePath,
                $observation->reason,
            )
            || !is_string($observation->flake)
            || preg_match(QUARANTINE_FLAKE_PATTERN, $observation->flake) !== 1
            || !is_string($observation->status)
            || !array_key_exists($observation->status, QUARANTINE_STATUS_COUNTERS)
            || !is_string($observation->file)
            || $observation->file === ''
            || str_starts_with($observation->file, '/')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $observation->file) === 1
            || !is_int($observation->line)
            || $observation->line < 1
            || !is_string($observation->project)
            || !is_array($observation->titlePath)
            || $observation->titlePath === []
            || array_filter($observation->titlePath, static fn(mixed $title): bool => !is_string($title)) !== []
            || !is_string($observation->reason)
        ) {
            continue;
        }

        $quarantine['tests'][] = [
            'flake' => $observation->flake,
            'status' => $observation->status,
            'file' => $observation->file,
            'line' => $observation->line,
            'project' => $observation->project,
            'titlePath' => $observation->titlePath,
            'reason' => $observation->reason,
        ];
        $quarantine['count']++;
        $counter = QUARANTINE_STATUS_COUNTERS[$observation->status];
        if ($counter !== null) {
            $quarantine[$counter]++;
        }
    }

    return $quarantine;
}

/**
 * @return array{count: int, failed: int, notRun: int, tests: list<array{
 *     flake: string, status: string, file: string, line: int, project: string,
 *     titlePath: list<string>, reason: string}>} A skipped or silent step's report.
 */
function noQuarantineTests(): array
{
    return ['count' => 0, 'failed' => 0, 'notRun' => 0, 'tests' => []];
}

/**
 * @param array{count: int, failed: int, notRun: int} $quarantine The step's observations.
 * @return string Optional fields appended after the existing rc and unstable fields.
 */
function quarantineLedgerField(array $quarantine): string
{
    if ($quarantine['count'] === 0) {
        return '';
    }

    return sprintf(
        ' quarantine=%d quarantine_failed=%d quarantine_not_run=%d',
        $quarantine['count'],
        $quarantine['failed'],
        $quarantine['notRun'],
    );
}

/**
 * Source addresses precede JSON observations without the producer's prefix, so
 * replaying a summary cannot create another observation. Zero stays silent.
 *
 * @param array<string, array{count: int, failed: int, notRun: int, tests: list<array{
 *     flake: string, status: string, file: string, line: int, project: string,
 *     titlePath: list<string>, reason: string}>}> $byStep Reports in planned step order.
 * @return string Complete summary lines, or nothing when no test was reported.
 */
function quarantineSummarySection(array $byStep): string
{
    $reported = array_filter($byStep, static fn(array $quarantine): bool => $quarantine['count'] > 0);
    if ($reported === []) {
        return '';
    }

    $section = sprintf(
        "=== quarantine: %d step(s), %d test(s), %d failed, %d not run ===\n",
        count($reported),
        array_sum(array_column($reported, 'count')),
        array_sum(array_column($reported, 'failed')),
        array_sum(array_column($reported, 'notRun')),
    );
    foreach ($reported as $id => $quarantine) {
        foreach ($quarantine['tests'] as $test) {
            $section .= sprintf(
                "  %-20s %s %s\n",
                $id,
                json_encode($test['file'] . ':' . $test['line'], QUARANTINE_JSON_FLAGS),
                json_encode($test, QUARANTINE_JSON_FLAGS),
            );
        }
    }

    return $section;
}
