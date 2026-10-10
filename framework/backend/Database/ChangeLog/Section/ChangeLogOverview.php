<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** Current journal size and live table coverage. */
final readonly class ChangeLogOverview
{
    /** @param list<string> $journaledTables Live main-database tables under the journal, by name */
    public function __construct(
        public int $journalEntries,
        public int $journalBytes,
        public ?DateTimeImmutable $oldestAt,
        public array $journaledTables,
        public int $liveTables,
    ) {
    }
}
