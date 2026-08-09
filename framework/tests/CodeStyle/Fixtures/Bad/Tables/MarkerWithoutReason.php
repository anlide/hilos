<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Tables;

/**
 * Deliberately broken sample: the marker is spelled correctly but names nothing
 * after the colon, so it classifies no place and is reported with a text of its
 * own rather than silently accepted.
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
}
