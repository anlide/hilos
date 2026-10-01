<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * A worker or agent session as its process knows it: the key it drew and the record that describes it.
 *
 * The description travels in every batch whose records name the session
 * ({@see AnalyticsJournalOutbox}), so the writer can insert the session from any one of them.
 */
final readonly class AnalyticsJournalSession
{
    /**
     * @param string $key Session key, 32 lowercase hex characters
     * @param array<string, mixed> $description Description record built by {@see AnalyticsJournalRecord}
     */
    public function __construct(
        public string $key,
        public array $description,
    ) {
    }
}
