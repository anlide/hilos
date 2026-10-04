<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The {@see AnalyticsJournalAgent} of a node → {@see AnalyticsWriterAgent}: a portion of a ready file (HIL-1154).
 *
 * Three answers share the shape. A portion: whole lines from {@see self::$offset}, the offset to
 * ask next, and whether the file ends with them. A file that is gone - deleted after an earlier
 * confirmation, or thrown away under a freeze - says {@see self::$gone}. No ready file at all is
 * an empty {@see self::$file}.
 */
final class AnalyticsJournalPortionSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: node whose journal answered, null outside a cluster. */
    public const string nodeId = 'nodeId';

    /** Payload key: ready file the portion belongs to, '' when the node has none. */
    public const string file = 'file';

    /** Payload key: byte offset the portion starts at. */
    public const string offset = 'offset';

    /** Payload key: byte offset to ask for next. */
    public const string nextOffset = 'nextOffset';

    /** Payload key: whole lines of the portion, without line breaks. */
    public const string lines = 'lines';

    /** Payload key: whether the file ends with this portion. */
    public const string complete = 'complete';

    /** Payload key: whether the file asked for is no longer there. */
    public const string gone = 'gone';

    /** Payload key: lines too long for a portion and omitted by the journal reader. */
    public const string passedOver = 'passedOver';

    /** The file an answer names when the node has no ready file at all. */
    public const string NO_READY_FILE = '';

    /**
     * @param ?string $nodeId Node whose journal answered, null outside a cluster
     * @param string $file Ready file the portion belongs to, {@see self::NO_READY_FILE} when the node has none
     * @param int $offset Byte offset the portion starts at
     * @param int $nextOffset Byte offset to ask for next
     * @param list<string> $lines Whole lines of the portion
     * @param bool $complete Whether the file ends with this portion
     * @param bool $gone Whether the file asked for is no longer there
     * @param int $passedOver Lines omitted for exceeding the journal line limit
     */
    public function __construct(
        public readonly ?string $nodeId,
        public readonly string $file,
        public readonly int $offset,
        public readonly int $nextOffset,
        public readonly array $lines,
        public readonly bool $complete,
        public readonly bool $gone,
        public readonly int $passedOver = 0,
    ) {
    }

    /**
     * @return array<string, mixed> Portion as it goes out to the writer
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::file => $this->file,
            self::offset => $this->offset,
            self::nextOffset => $this->nextOffset,
            self::lines => $this->lines,
            self::complete => $this->complete,
            self::gone => $this->gone,
            self::passedOver => $this->passedOver,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of one portion
     * @return static Restored payload
     * @throws InvalidFormatException When a field is absent or of the wrong type
     */
    public static function fromArray(array $data): static
    {
        $lines = self::optionalStringList($data, self::lines);
        if ($lines === null) {
            throw new InvalidFormatException('Payload carries no list of strings under key ' . self::lines);
        }

        // external-boundary: an older journal agent sends no passedOver field before loss counting is available
        $passedOver = self::optionalInt($data, self::passedOver) ?? 0;
        if ($passedOver < 0) {
            throw new InvalidFormatException('Payload carries a negative count under key ' . self::passedOver);
        }

        return new static(
            nodeId: self::optionalString($data, self::nodeId),
            file: self::requireString($data, self::file),
            offset: self::requireInt($data, self::offset),
            nextOffset: self::requireInt($data, self::nextOffset),
            lines: $lines,
            complete: self::requireBool($data, self::complete),
            gone: self::requireBool($data, self::gone),
            passedOver: $passedOver,
        );
    }
}
