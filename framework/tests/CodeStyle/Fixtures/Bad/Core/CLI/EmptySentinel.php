<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Core\CLI;

/**
 * Deliberately broken sample: `Core/CLI/` joined the checked zone in phase 2b,
 * so the fallback below is reported here and was silent before.
 */
final class EmptySentinel
{
    /**
     * @param array<string, string> $options Parsed command options
     * @return string Scope option, empty when the operator named none
     */
    public function scope(array $options): string
    {
        return $options['scope'] ?? '';
    }
}
