<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Tables;

/**
 * Deliberately broken sample: `Tables/` joined the checked zone in the second
 * phase, so the fallback below is reported here and was silent before.
 */
final class EmptySentinel
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
