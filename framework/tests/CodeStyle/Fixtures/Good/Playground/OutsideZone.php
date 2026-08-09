<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Playground;

/**
 * Negative sample outside the checked zone: the same fallback the Bad sample is
 * reported for is silent here, because the zone grows one phase at a time and
 * this segment belongs to no phase at all.
 */
final class OutsideZone
{
    /**
     * @param array<string, string> $columns Column titles keyed by field name
     * @param string $field Field to title
     * @return string Column title, empty when the table declared none
     */
    public function title(array $columns, string $field): string
    {
        return $columns[$field] ?? '';
    }
}
