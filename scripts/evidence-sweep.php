<?php

declare(strict_types=1);

/**
 * What a full run sweeps out of its own evidence directories: the log and the snapshot
 * of a step the manifest no longer lists.
 *
 * `prepareLogDir()` and `prepareArtifactDir()` drop the evidence of the steps in the
 * PLAN and of nothing else, so that re-running one step leaves its neighbours readable.
 * That rule is right, and its side effect is that a step deleted from
 * `scripts/test-suite.php` leaves its last log behind for good. On HIL-853 such a dead
 * log answered a grep across the whole directory with exactly the line the fix was
 * meant to remove — out of a demo the manifest does not contain at all — and the
 * conclusion "the fix does not work" was a second away. The price is asymmetric: a
 * false red read off a dead log costs hours, and no true conclusion is ever drawn from
 * one.
 *
 * A full run is the only moment when "the step is not in the plan" reliably means "the
 * step does not exist", so the sweep happens there and nowhere else.
 *
 * This file only declares functions and executes nothing, so that
 * `scripts/run-test-suite.php` and `framework/tests/Unit/EvidenceSweepTest.php` can
 * both require it.
 */

/** The suffix a per-step log carries; whatever stands before it is the step id. */
const EVIDENCE_LOG_SUFFIX = '.log';

/**
 * The log file names in a listing that belong to no step of the manifest.
 *
 * Anything without the suffix is passed over in silence, and that is what keeps the rc
 * ledger and the `artifacts` subdirectory out of the rule's reach even when they sit
 * inside the log directory, as they both do by default.
 *
 * @param array<int, string> $entries Directory entries as `scandir()` hands them back.
 * @param array<int, string> $knownIds Step ids the manifest lists.
 * @return array<int, string> File names, not paths.
 */
function staleLogNames(array $entries, array $knownIds): array
{
    $stale = [];
    foreach ($entries as $entry) {
        if (!str_ends_with($entry, EVIDENCE_LOG_SUFFIX)) {
            continue;
        }
        if (in_array(substr($entry, 0, -strlen(EVIDENCE_LOG_SUFFIX)), $knownIds, true)) {
            continue;
        }
        $stale[] = $entry;
    }

    return $stale;
}

/**
 * The entry names in the artifact directory that belong to no step of the manifest.
 *
 * Whether an entry is a directory is not asked here: the caller checks that before it
 * walks anything, and keeping the filter clear of the filesystem is what lets a unit
 * test hold it.
 *
 * @param array<int, string> $entries Directory entries as `scandir()` hands them back.
 * @param array<int, string> $knownIds Step ids the manifest lists.
 * @return array<int, string> Entry names, not paths.
 */
function staleArtifactNames(array $entries, array $knownIds): array
{
    $stale = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $knownIds, true)) {
            continue;
        }
        $stale[] = $entry;
    }

    return $stale;
}

/**
 * Delete the evidence of every step the manifest no longer lists, and report what
 * actually went.
 *
 * Only a confirmed deletion is reported: a log whose `unlink()` returned true, and a
 * snapshot directory that is gone once the walk is over. A file that will not go —
 * wrong owner, wrong permissions — is passed over in silence and the run carries on.
 * That is the rule the step-artifact collector already keeps
 * (`scripts/step-artifacts.php`): gathering evidence never changes a verdict.
 *
 * @param string $logDir Directory the per-step logs live in.
 * @param string $artifactDir Directory the per-step snapshots live under.
 * @param array<int, string> $knownIds Step ids the manifest lists.
 * @return array<string, array<int, string>> What was swept, by step id; each value
 *     names `log`, `artifacts`, or both.
 */
function sweepStaleEvidence(string $logDir, string $artifactDir, array $knownIds): array
{
    $swept = [];
    foreach (staleLogNames(scandir($logDir) ?: [], $knownIds) as $name) {
        if (unlink($logDir . '/' . $name)) {
            $swept[substr($name, 0, -strlen(EVIDENCE_LOG_SUFFIX))][] = 'log';
        }
    }
    foreach (staleArtifactNames(scandir($artifactDir) ?: [], $knownIds) as $name) {
        $path = $artifactDir . '/' . $name;
        if (!is_dir($path)) {
            continue;
        }
        removeArtifactTree($path);
        if (!is_dir($path)) {
            $swept[$name][] = 'artifacts';
        }
    }

    return $swept;
}

/**
 * The sweep's section, printed before the run starts, or nothing at all when there was
 * nothing to sweep.
 *
 * The grammar is {@see unstableSummarySection()}'s, down to the width of the id column:
 * a run with nothing to report writes nothing here, so a green log is the same text it
 * has always been and the section showing up is itself the news.
 *
 * The section is keyed by step rather than by file because one dead step leaves both
 * halves of its evidence behind, and two lines about it would read as two steps.
 *
 * @param array<string, array<int, string>> $swept What was swept, by step id.
 * @return string Whole lines, ready to write.
 */
function sweptEvidenceSection(array $swept): string
{
    if ($swept === []) {
        return '';
    }
    $section = sprintf("=== swept: %d step(s) no longer in the manifest ===\n", count($swept));
    foreach ($swept as $id => $kinds) {
        $section .= sprintf("  %-20s %s\n", $id, implode(', ', $kinds));
    }

    return $section;
}

/**
 * Delete one step's previous snapshot, whole. A snapshot is the runner's own output
 * and nothing else writes there, so this walks the tree rather than shelling out.
 *
 * @param string $path The directory to remove.
 */
function removeArtifactTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($path . '/' . $entry)) {
            removeArtifactTree($path . '/' . $entry);
            continue;
        }
        unlink($path . '/' . $entry);
    }
    rmdir($path);
}
