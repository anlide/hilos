<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Number of records a receipt changed in one table. */
final readonly class ChangeLogTouchedTable
{
    public function __construct(
        public string $table,
        public int $records,
        public ?ChangeLogTouchedRecord $single,
    ) {
    }
}
