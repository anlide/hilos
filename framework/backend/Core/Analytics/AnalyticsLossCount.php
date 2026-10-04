<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Core\Exception\InvalidFormatException;

/** A counted group of events lost for one reason between two unix millisecond moments. */
final readonly class AnalyticsLossCount
{
    /** Largest count a loss row's INT UNSIGNED event_count column holds. */
    private const int MAX_EVENTS = 4294967295;

    public function __construct(
        public AnalyticsLossReason $reason,
        public int $events,
        public int $fromTs,
        public int $toTs,
    ) {
    }

    /**
     * @return array<string, int|string> The append frame and journal record fields
     */
    public function toArray(): array
    {
        return [
            AnalyticsJournalRecord::KEY_REASON => $this->reason->value,
            AnalyticsJournalRecord::KEY_EVENTS => $this->events,
            AnalyticsJournalRecord::KEY_FROM_TS => $this->fromTs,
            AnalyticsJournalRecord::KEY_TO_TS => $this->toTs,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire fields of one count
     * @return self A validated count
     * @throws InvalidFormatException When its reason, count or moments are malformed
     */
    public static function fromArray(array $data): self
    {
        $reason = $data[AnalyticsJournalRecord::KEY_REASON] ?? null;
        $events = $data[AnalyticsJournalRecord::KEY_EVENTS] ?? null;
        $fromTs = $data[AnalyticsJournalRecord::KEY_FROM_TS] ?? null;
        $toTs = $data[AnalyticsJournalRecord::KEY_TO_TS] ?? null;
        if (
            !is_string($reason) || AnalyticsLossReason::tryFrom($reason) === null
            || !is_int($events) || $events < 1 || $events > self::MAX_EVENTS
            || !is_int($fromTs) || $fromTs < 0 || !is_int($toTs) || $toTs < 0
        ) {
            throw new InvalidFormatException('Invalid analytics loss count');
        }

        return new self(AnalyticsLossReason::from($reason), $events, $fromTs, $toTs);
    }

    /**
     * @return string The journal line, without its line break
     * @throws InvalidFormatException When a count cannot be encoded
     */
    public function toLine(): string
    {
        $line = AnalyticsJournalRecord::encode(AnalyticsJournalRecord::loss(
            $this->reason, $this->events, $this->fromTs, $this->toTs,
        ));
        if ($line === null) {
            throw new InvalidFormatException('Cannot encode analytics loss count');
        }

        return $line;
    }
}
