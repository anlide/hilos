<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;

/**
 * A migration number of the track is taken by more than one file of one direction (HIL-1238).
 *
 * Raised by {@see Migration::refuseInconsistentTrack()} before every rollout, on a fresh database as
 * on a live one, and by every lookup of a migration file by its number - a rollback, a retry. The
 * migrator keys a migration by its number alone, so of two files with one number it would apply
 * the first by name and never the second, and nothing would say so. A node's watchdog, the CLI and
 * a restore read it as any other migration failure.
 */
final class MigrationNumberTakenTwiceException extends DatabaseException
{
    /**
     * @param string $track Migration track the files belong to
     * @param array<int, list<string>> $filesByNumber Number => every file of that number
     * @return self Exception instance
     */
    public static function forNumbers(string $track, array $filesByNumber): self
    {
        $collisions = [];
        foreach ($filesByNumber as $number => $files) {
            $collisions[] = "number {$number} is taken by more than one file (" . implode(', ', $files) . ')';
        }

        return new self(
            "Migration track {$track}: " . implode('; ', $collisions) . '; give each file a number of its own',
        );
    }
}
