<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Full key of a journal row in one table's history. */
final readonly class ChangeLogHistoryAnchor
{
    /**
     * @param int $createdAt Source epoch milliseconds
     * @param int $id Journal row number
     */
    public function __construct(public int $createdAt, public int $id)
    {
    }
}
