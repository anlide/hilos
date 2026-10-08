<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Live columns and service triggers for one table. */
final readonly class ChangeLogTableDetail
{
    /**
     * @param ChangeLogTableSummary $summary Table classification and activity
     * @param list<ChangeLogColumn> $columns Columns in live schema order
     * @param list<ChangeLogTrigger> $triggers Service triggers, or none outside the journal
     */
    public function __construct(
        public ChangeLogTableSummary $summary,
        public array $columns,
        public array $triggers,
    ) {
    }
}
