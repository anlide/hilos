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
 * {@see AnalyticsWriterAgent} → the {@see AnalyticsJournalAgent} of a node: this ready file is in the database (HIL-1154).
 *
 * {@see self::$nodeId} is the address ({@see AgentSignalConfigKey::NODE_FIELD}), as on the read.
 */
final class AnalyticsJournalLoadedSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: node whose journal holds the file, null for the sender's own. */
    public const string nodeId = 'nodeId';

    /** Payload key: the ready file the writer loaded. */
    public const string file = 'file';

    /**
     * @param ?string $nodeId Node whose journal holds the file, null for the sender's own
     * @param string $file Ready file the writer loaded
     */
    public function __construct(
        public readonly ?string $nodeId,
        public readonly string $file,
    ) {
    }

    /**
     * @return array<string, mixed> Confirmation as it goes out to the journal agent
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::file => $this->file,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of one confirmation
     * @return static Restored payload
     * @throws InvalidFormatException When a field is absent or of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            nodeId: self::optionalString($data, self::nodeId),
            file: self::requireString($data, self::file),
        );
    }
}
