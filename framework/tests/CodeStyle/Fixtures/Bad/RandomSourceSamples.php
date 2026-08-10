<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Utils\Helpers\RandomHelper;

/**
 * Deliberately broken sample: this file is not in the rule's list, so every call to
 * the tolerant axis below must be reported by RANDOM-SOURCE - whichever of the three
 * methods it is, and whether the helper is written by its imported short name or
 * fully qualified.
 */
final class RandomSourceSamples
{
    /**
     * @return array<int, string|int> Values a refused entropy source would quietly weaken
     */
    public function mint(): array
    {
        return [
            RandomHelper::bytes(16),
            RandomHelper::hex(8),
            \Hilos\Utils\Helpers\RandomHelper::integer(1000, 9999),
        ];
    }
}
