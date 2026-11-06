<?php

declare(strict_types=1);

namespace Hilos\Database;

/** One actual migration row, without deriving missing history from a level. */
final readonly class MigrationRecord
{
    /**
     * @param int $index Recorded migration number
     * @param bool $failed Whether the run left the migration failed
     */
    public function __construct(public int $index, public bool $failed)
    {
    }
}
