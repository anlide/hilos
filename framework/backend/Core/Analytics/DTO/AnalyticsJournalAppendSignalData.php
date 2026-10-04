<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsLossCount;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * A process → the {@see AnalyticsJournalAgent} of its node: lines to append to the journal (HIL-1154).
 *
 * Each line is one encoded record ({@see AnalyticsJournalRecord::encode()}), without its line
 * break. The batch starts with the descriptions of every session its records name.
 */
final class AnalyticsJournalAppendSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the lines, in the order they are to be written. */
    public const string lines = 'lines';

    /** Payload key: event records in this batch, excluding session descriptions. */
    public const string events = 'events';

    /** Payload key: source loss counts. */
    public const string losses = 'losses';

    /**
     * @param list<string> $lines Encoded records, in order
     * @param int $events Event records among the lines
     * @param list<AnalyticsLossCount> $losses Losses counted by the source
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $events,
        public readonly array $losses = [],
    ) {
    }

    /**
     * @return array<string, mixed> Batch as it goes out to the journal agent
     */
    public function toArray(): array
    {
        return [
            self::lines => $this->lines,
            self::events => $this->events,
            self::losses => array_map(static fn(AnalyticsLossCount $loss): array => $loss->toArray(), $this->losses),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of one batch
     * @return static Restored payload
     * @throws InvalidFormatException When lines, count or losses are malformed
     */
    public static function fromArray(array $data): static
    {
        $lines = self::optionalStringList($data, self::lines);
        if ($lines === null) {
            throw new InvalidFormatException('Payload carries no list of strings under key ' . self::lines);
        }

        $losses = self::optionalArray($data, self::losses) ?? [];
        if (!array_is_list($losses)) {
            throw new InvalidFormatException('Payload carries no list under key ' . self::losses);
        }

        return new static(
            lines: $lines,
            events: self::requireInt($data, self::events),
            losses: array_map(static function (mixed $loss): AnalyticsLossCount {
                if (!is_array($loss)) {
                    throw new InvalidFormatException('Invalid analytics loss count');
                }

                return AnalyticsLossCount::fromArray($loss);
            }, $losses),
        );
    }
}
