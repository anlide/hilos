<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** One journal row with an optional receipt header for attribution. */
final readonly class ChangeLogHistoryRow
{
    public function __construct(public ChangeLogEntry $entry, public ?ChangeLogReceiptHeader $receipt)
    {
    }
}
