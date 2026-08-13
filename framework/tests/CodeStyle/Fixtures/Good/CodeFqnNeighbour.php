<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * The class {@see CodeFqnLookAlikes} names without an import. PSR-4 puts one
 * namespace in one directory, which is how the rule finds it without an index.
 */
final class CodeFqnNeighbour
{
    /**
     * @return array<int, string> Values the look-alike sample encodes
     */
    public static function values(): array
    {
        return ['one', 'two'];
    }
}
