<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * A record the freeze kept the collector from handing over, with the sessions it names.
 *
 * Today only an agent's stop: a true fact of the database under the freeze, stamped with its own
 * moment and handed over on the first call after the freeze lets the collector go.
 */
final readonly class AnalyticsHeldRecord
{
    /**
     * @param array<string, mixed> $record Record built by {@see AnalyticsJournalRecord}
     * @param array<string, array<string, mixed>> $sessions Session key to the description of every session the record names
     */
    public function __construct(
        public array $record,
        public array $sessions,
    ) {
    }
}
