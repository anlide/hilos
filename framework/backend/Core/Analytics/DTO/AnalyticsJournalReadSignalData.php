<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * {@see AnalyticsWriterAgent} → the {@see AnalyticsJournalAgent} of a node: the next portion of a ready file (HIL-1154).
 *
 * {@see self::$nodeId} is the address ({@see AgentSignalConfigKey::NODE_FIELD}).
 */
final class AnalyticsJournalReadSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: node whose journal is read. */
    public const string nodeId = 'nodeId';

    /** Payload key: ready file to read, '' for the oldest ready file of that node. */
    public const string file = 'file';

    /** Payload key: byte offset in the file the portion starts at. */
    public const string offset = 'offset';

    /** The file a read names when it asks for the oldest ready file of the node. */
    public const string OLDEST_READY = '';

    /**
     * @param string $nodeId Node whose journal is read
     * @param string $file Ready file to read, {@see self::OLDEST_READY} for the oldest ready file
     * @param int $offset Byte offset the portion starts at
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $file,
        public readonly int $offset,
    ) {
    }

    /**
     * @return array<string, mixed> Request as it goes out to the journal agent
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::file => $this->file,
            self::offset => $this->offset,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of one request
     * @return static Restored payload
     * @throws InvalidFormatException When a field is of the wrong type, or the offset is absent or negative
     */
    public static function fromArray(array $data): static
    {
        $offset = self::requireInt($data, self::offset);
        if ($offset < 0) {
            throw new InvalidFormatException('Analytics journal read carries a negative offset');
        }

        $nodeId = self::requireString($data, self::nodeId);
        if ($nodeId === '') {
            throw new InvalidFormatException('Analytics journal node id must not be empty');
        }

        return new static(
            nodeId: $nodeId,
            file: self::requireString($data, self::file),
            offset: $offset,
        );
    }
}
