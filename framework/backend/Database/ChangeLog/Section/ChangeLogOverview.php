<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Current journal size and live table coverage. */
final readonly class ChangeLogOverview
{
    /**
     * @param int $journalEntries Number of journal records
     * @param int $journalBytes Physical journal size in bytes
     * @param ?int $oldestAt Source epoch milliseconds of the lowest journal id, or null for an empty journal
     * @param list<string> $journaledTables Live main-database tables under the journal, by name
     * @param int $liveTables Number of live main-database tables
     */
    public function __construct(
        public int $journalEntries,
        public int $journalBytes,
        public ?int $oldestAt,
        public array $journaledTables,
        public int $liveTables,
    ) {
    }
}
