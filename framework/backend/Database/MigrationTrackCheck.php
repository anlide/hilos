<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Backup\RestoreMigrationGuard;

/**
 * The two ways a migration track goes unapplied in silence, judged from names and numbers (HIL-1238).
 *
 * A number taken by two files collapses into one: the migrator applies the first of them by name
 * and never the second, on a fresh database and a live one alike. A file numbered below the level a
 * database is at, never applied there, cannot be told from an applied one by the level alone, and
 * stays unapplied for good. {@see Migration::refuseInconsistentTrack()} reads the directory and the
 * `migration` table and refuses on what these functions find.
 *
 * Pure on purpose, the shape {@see RestoreMigrationGuard} established: the cases that
 * decide the rule - the lowest row, an empty table, a failed row - cannot be staged honestly on the
 * shared test database, whose `migration` table carries real rows, so they are proven here without
 * a database or a filesystem.
 */
final class MigrationTrackCheck
{
    /**
     * An up file: `001_create_users.sql` or `1_up.sql`, but no name that carries `_down` after its
     * number and no `1_down.sql`.
     */
    public const string UP_FILE_PATTERN = '/^(\d+)_(?!down\.sql$)(?!.*_down).*\.sql$/';

    /** A down file: `001_create_users_down.sql` or `1_down.sql`. */
    public const string DOWN_FILE_PATTERN = '/^(\d+)_(?:.*_)?down\.sql$/';

    /**
     * Finds the numbers taken by more than one file of one direction.
     *
     * An up and a down file of one number are a pair, not a duplicate; two up files or two down
     * files of one number are. Every file of such a number is named, both directions, so the
     * refusal shows the whole collision at once.
     *
     * @param list<string> $fileNames Entry names of the track directory
     * @return array<int, list<string>> Number => every file of that number in name order, numbers ascending; empty when none
     */
    public static function takenTwice(array $fileNames): array
    {
        $upCount = [];
        $downCount = [];
        $filesByNumber = [];
        foreach ($fileNames as $fileName) {
            if (preg_match(self::UP_FILE_PATTERN, $fileName, $matches) === 1) {
                $number = (int)$matches[1];
                $upCount[$number] = ($upCount[$number] ?? 0) + 1;
                $filesByNumber[$number][] = $fileName;
            } elseif (preg_match(self::DOWN_FILE_PATTERN, $fileName, $matches) === 1) {
                $number = (int)$matches[1];
                $downCount[$number] = ($downCount[$number] ?? 0) + 1;
                $filesByNumber[$number][] = $fileName;
            }
        }

        $takenTwice = [];
        foreach ($filesByNumber as $number => $files) {
            if (($upCount[$number] ?? 0) > 1 || ($downCount[$number] ?? 0) > 1) {
                sort($files);
                $takenTwice[$number] = $files;
            }
        }
        ksort($takenTwice);

        return $takenTwice;
    }

    /**
     * Finds the up files a database at `$level` skipped and will never apply.
     *
     * A number is skipped when the `migration` table has no row for it at all and it lies strictly
     * between the lowest row and the level. Below the lowest row lies history a schema archive
     * restored before HIL-1238 declared with one row at its own level; above it every applied file
     * has its row, because the migrator writes one per file in order. A row of either `failed`
     * value counts as touched: a failed row below the level is the business of
     * `db:migration:retry`, not of this check.
     *
     * @param list<int> $available Numbers of the track's up files
     * @param list<int> $recorded Every index the `migration` table holds, whatever its `failed`
     * @param int $level Highest index recorded with `failed` = 0; 0 when there is none
     * @return list<int> Skipped numbers, ascending; empty when nothing was skipped
     */
    public static function skipped(array $available, array $recorded, int $level): array
    {
        if ($recorded === []) {
            return [];
        }

        $lowest = min($recorded);
        $skipped = array_values(array_filter(
            array_diff($available, $recorded),
            static fn (int $number): bool => $number > $lowest && $number < $level,
        ));
        sort($skipped);

        return $skipped;
    }
}
