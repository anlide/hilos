<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Tables;

/**
 * Deliberately broken sample: the marker is spelled correctly but names nothing
 * after the colon, so it classifies no place and is reported with a text of its
 * own rather than silently accepted. It fails that way for every spelling of the
 * sentinel, not only for `??`.
 */
final class MarkerWithoutReason
{
    /**
     * @param array<string, string> $row Row as the database driver returned it
     * @return string Column title, empty when the stored title is empty
     */
    public function title(array $row): string
    {
        // external-boundary:
        return $row['title'] ?? '';
    }

    /**
     * @param array<int, string> $argv Command line as the shell handed it over
     * @return string Requested table, empty when the operator named none
     */
    public function requestedTable(array $argv): string
    {
        // external-boundary:
        return isset($argv[1]) ? $argv[1] : '';
    }
}
