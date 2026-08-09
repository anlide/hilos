<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\WholeRoot\Playground;

/**
 * Deliberately broken sample for the whole-root mode, kept in a root of its own so
 * the zone mode never reaches it. The segment is the same one `Good/Playground`
 * uses to stay silent under a zone: a root judged entire has no segment to be
 * outside of, and that difference is the whole of what this fixture proves.
 */
final class JudgedAnyway
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
