<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class MigrationRow
{
    /**
     * @param int $index Explicit migration number, including an explicitly recorded zero
     * @param bool $failed Whether this migration was recorded as failed
     */
    public function __construct(public int $index, public bool $failed)
    {
    }
}
