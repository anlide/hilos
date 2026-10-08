<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** Full key of a journal row in one table's history. */
final readonly class ChangeLogHistoryAnchor
{
    public function __construct(public DateTimeImmutable $createdAt, public int $id)
    {
    }
}
