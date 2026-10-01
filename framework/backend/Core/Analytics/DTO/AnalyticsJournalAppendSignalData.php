<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
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

    /**
     * @param list<string> $lines Encoded records, in order
     */
    public function __construct(
        public readonly array $lines,
    ) {
    }

    /**
     * @return array<string, mixed> Batch as it goes out to the journal agent
     */
    public function toArray(): array
    {
        return [
            self::lines => $this->lines,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of one batch
     * @return static Restored payload
     * @throws InvalidFormatException When the lines are absent or anything but a list of strings
     */
    public static function fromArray(array $data): static
    {
        $lines = self::optionalStringList($data, self::lines);
        if ($lines === null) {
            throw new InvalidFormatException('Payload carries no list of strings under key ' . self::lines);
        }

        return new static(lines: $lines);
    }
}
