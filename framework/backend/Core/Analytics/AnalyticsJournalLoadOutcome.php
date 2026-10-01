<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * What one {@see AnalyticsJournalLoader::load()} did with a journal file.
 */
final readonly class AnalyticsJournalLoadOutcome
{
    /**
     * @param bool $alreadyLoaded True when an earlier load had remembered the file and nothing was written now
     * @param int $recordCount Records the file carried, its header aside; 0 when it was already loaded
     * @param array<string, int> $skipped Records passed over, by {@see AnalyticsJournalSkip} value; only reasons that occurred
     */
    public function __construct(
        public bool $alreadyLoaded,
        public int $recordCount,
        public array $skipped,
    ) {
    }

    /**
     * @return self The outcome of a file an earlier load already wrote
     */
    public static function alreadyLoaded(): self
    {
        return new self(true, 0, []);
    }

    /**
     * @return int Records passed over, all reasons together
     */
    public function skippedCount(): int
    {
        return array_sum($this->skipped);
    }
}
