<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** One live table's journal declaration and activity. */
final readonly class ChangeLogTableSummary
{
    /**
     * @param string $name Live table name
     * @param ChangeLogTableOwner $owner Framework or project declaration
     * @param bool $journaled Whether its mounted Entity opted in
     * @param array<string, int> $modeCounts Counts by JournalColumnMode value
     * @param ?int $changesLast24h Recent writes, or null outside the journal
     * @param ?DateTimeImmutable $lastChangeAt Most recent journal write
     */
    public function __construct(
        public string $name,
        public ChangeLogTableOwner $owner,
        public bool $journaled,
        public array $modeCounts,
        public ?int $changesLast24h,
        public ?DateTimeImmutable $lastChangeAt,
    ) {
    }
}
