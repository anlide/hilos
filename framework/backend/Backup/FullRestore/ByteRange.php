<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** Source bytes omitted from a primary staging file. */
final readonly class ByteRange
{
    /**
     * @param int $start Inclusive byte offset
     * @param int $end Exclusive byte offset
     */
    public function __construct(public int $start, public int $end)
    {
    }
}
