<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

/** A generator suspends BEFORE work and receives the number of bytes granted. */
final readonly class RtWork
{
    /**
     * @param int $bytes Maximum bytes in the next part; zero requests only a structural step
     */
    public function __construct(public int $bytes = 0)
    {
    }
}
