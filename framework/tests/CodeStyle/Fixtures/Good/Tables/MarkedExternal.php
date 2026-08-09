<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Tables;

/**
 * Negative sample inside the checked zone: the very fallback the Bad sample is
 * reported for is legal here, because the marker above it says the value arrives
 * from outside the process and an empty one is what the outside sends.
 */
final class MarkedExternal
{
    /**
     * @param array<string, string> $row Row as the database driver returned it
     * @return string Column title, empty when the stored title is empty
     */
    public function title(array $row): string
    {
        // external-boundary: a NOT NULL column may legitimately hold an empty title
        return $row['title'] ?? '';
    }
}
