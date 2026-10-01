<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;

/**
 * Migration files numbered below the database's level were never applied to it (HIL-1238).
 *
 * Raised by {@see Migration::refuseInconsistentTrack()} before every rollout, including one with
 * nothing to apply. The migrator applies only what lies above the level, so such a file - a number
 * taken on a branch while a higher one landed first - would stay unapplied for good. It is not
 * applied out of order instead: it was written for a database without the migrations above it, and
 * one history would then build two schemas. The cure is a new number above the level, with SQL that
 * holds where the file already ran under its old number - a database built from scratch in between.
 * A node's watchdog, the CLI and a restore read it as any other migration failure.
 */
final class MigrationSkippedBelowLevelException extends DatabaseException
{
    /**
     * @param string $track Migration track the files belong to
     * @param list<string> $files Up files never applied, in name order
     * @param int $level Level the database is at
     * @return self Exception instance
     */
    public static function forFiles(string $track, array $files, int $level): self
    {
        $names = implode(', ', $files);
        if (count($files) === 1) {
            return new self(
                "Migration track {$track}: {$names} is below the database's level {$level} and was never applied;"
                . " renumber it above {$level}, with SQL that holds on a database that already has it (IF NOT EXISTS)",
            );
        }

        return new self(
            "Migration track {$track}: {$names} are below the database's level {$level} and were never applied;"
            . " renumber them above {$level}, with SQL that holds on a database that already has them (IF NOT EXISTS)",
        );
    }
}
