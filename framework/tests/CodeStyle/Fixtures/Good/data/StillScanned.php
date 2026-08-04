<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Data;

/**
 * Sits in a directory named `data`, which the scanner must still walk into: only
 * an exactly pinned path is left out, never whatever carries a given name.
 */
final class StillScanned
{
    /**
     * @return string Nothing interesting; this sample is about where it lives
     */
    public function sample(): string
    {
        return 'clean';
    }
}
