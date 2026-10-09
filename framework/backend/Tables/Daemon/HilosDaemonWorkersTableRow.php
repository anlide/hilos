<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One worker in a node's daemon picture, keyed by kind and index. */
final class HilosDaemonWorkersTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string index = 'index';
    public const string kind = 'kind';
    public const string pid = 'pid';
    public const string memoryBytes = 'memoryBytes';
    public const string agentCount = 'agentCount';
    public const string agentIds = 'agentIds';
    public const string logStream = 'logStream';

    /**
     * @param string $rowKey Worker kind and index
     * @param int $index Worker index shared across kinds
     * @param string $kind Regular or monopolistic worker kind
     * @param ?int $pid Process id, or null when unavailable
     * @param ?int $memoryBytes RSS, or null when unavailable
     * @param int $agentCount Number of started agents
     * @param list<string> $agentIds Started agent ids in picture order
     * @param string $logStream Live worker log stream name
     */
    public function __construct(
        public string $rowKey,
        public int $index,
        public string $kind,
        public ?int $pid,
        public ?int $memoryBytes,
        public int $agentCount,
        public array $agentIds,
        public string $logStream,
    ) {
    }

    /** @return string Stable worker key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Worker row payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::index => $this->index,
            self::kind => $this->kind,
            self::pid => $this->pid,
            self::memoryBytes => $this->memoryBytes,
            self::agentCount => $this->agentCount,
            self::agentIds => $this->agentIds,
            self::logStream => $this->logStream,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw worker row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        $agentIds = self::optionalStringList($data, self::agentIds);
        if ($agentIds === null) {
            throw new InvalidFormatException('Worker row carries no agent id list');
        }

        return new static(
            rowKey: self::requireString($data, self::rowKey),
            index: self::requireInt($data, self::index),
            kind: self::requireString($data, self::kind),
            pid: self::optionalInt($data, self::pid),
            memoryBytes: self::optionalInt($data, self::memoryBytes),
            agentCount: self::requireInt($data, self::agentCount),
            agentIds: $agentIds,
            logStream: self::requireString($data, self::logStream),
        );
    }
}
