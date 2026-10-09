<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One started agent in a node's daemon picture, keyed by its instance id. */
final class HilosDaemonAgentsTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string agentId = 'agentId';
    public const string workerIndex = 'workerIndex';
    public const string workerKind = 'workerKind';
    public const string placement = 'placement';
    public const string idle = 'idle';
    public const string ownsRt = 'ownsRt';
    public const string ownsDb = 'ownsDb';
    public const string logStream = 'logStream';

    public const string WIDTH_WHOLE = 'whole';
    public const string WIDTH_ROWS = 'rows';
    public const string WIDTH_SET = 'set';
    public const string OWNERSHIP_COLLECTION = 'collection';
    public const string OWNERSHIP_WIDTH = 'width';

    /**
     * @param string $rowKey Agent instance id
     * @param string $agentId Agent instance id
     * @param int $workerIndex Index of the live worker
     * @param string $workerKind Regular or monopolistic worker kind
     * @param string $placement Node, leader, or policy placement
     * @param bool $idle Whether the agent has an idle timeout
     * @param list<array{collection: string, width: string}> $ownsRt Declared runtime ownership
     * @param list<array{collection: string, width: string}> $ownsDb Declared database ownership
     * @param string $logStream Live agent log filename
     */
    public function __construct(
        public string $rowKey,
        public string $agentId,
        public int $workerIndex,
        public string $workerKind,
        public string $placement,
        public bool $idle,
        public array $ownsRt,
        public array $ownsDb,
        public string $logStream,
    ) {
    }

    /** @return string Stable agent instance key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Agent row payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::agentId => $this->agentId,
            self::workerIndex => $this->workerIndex,
            self::workerKind => $this->workerKind,
            self::placement => $this->placement,
            self::idle => $this->idle,
            self::ownsRt => $this->ownsRt,
            self::ownsDb => $this->ownsDb,
            self::logStream => $this->logStream,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw agent row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field or ownership entry is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            agentId: self::requireString($data, self::agentId),
            workerIndex: self::requireInt($data, self::workerIndex),
            workerKind: self::requireString($data, self::workerKind),
            placement: self::requireString($data, self::placement),
            idle: self::requireBool($data, self::idle),
            ownsRt: self::ownershipList($data, self::ownsRt),
            ownsDb: self::ownershipList($data, self::ownsDb),
            logStream: self::requireString($data, self::logStream),
        );
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @param string $field Ownership half
     * @return list<array{collection: string, width: string}> Validated ownership entries
     * @throws InvalidFormatException When an entry has an invalid shape or width
     */
    private static function ownershipList(array $data, string $field): array
    {
        $entries = self::requireArray($data, $field);
        if (!array_is_list($entries)) {
            throw new InvalidFormatException("Agent row {$field} must be a list");
        }

        $ownership = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new InvalidFormatException("Agent row {$field} contains an invalid entry");
            }
            $collection = self::requireString($entry, self::OWNERSHIP_COLLECTION);
            $width = self::requireString($entry, self::OWNERSHIP_WIDTH);
            if (!in_array($width, [self::WIDTH_WHOLE, self::WIDTH_ROWS, self::WIDTH_SET], true)) {
                throw new InvalidFormatException("Agent row {$field} contains an invalid ownership width");
            }
            $ownership[] = [self::OWNERSHIP_COLLECTION => $collection, self::OWNERSHIP_WIDTH => $width];
        }

        return $ownership;
    }
}
