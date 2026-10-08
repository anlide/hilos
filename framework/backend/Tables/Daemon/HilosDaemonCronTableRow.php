<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One cron rule in a node's daemon picture, with a stable owner/name key. */
final class HilosDaemonCronTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string agentId = 'agentId';
    public const string name = 'name';
    public const string expression = 'expression';
    public const string lastRunAt = 'lastRunAt';
    public const string nextRunAt = 'nextRunAt';
    public const string idleReason = 'idleReason';

    /**
     * @param string $rowKey Encoded owner and rule name
     * @param ?string $agentId Agent owner, or null for a daemon rule
     * @param string $name Rule name
     * @param string $expression Cron expression
     * @param ?int $lastRunAt Last actual firing, in Unix seconds
     * @param ?int $nextRunAt Next matching minute, in Unix seconds
     * @param ?string $idleReason Why a daemon rule does not run on this node
     */
    public function __construct(
        public string $rowKey,
        public ?string $agentId,
        public string $name,
        public string $expression,
        public ?int $lastRunAt,
        public ?int $nextRunAt,
        public ?string $idleReason,
    ) {
    }

    /** @return string Stable row key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Cron rule payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::agentId => $this->agentId,
            self::name => $this->name,
            self::expression => $this->expression,
            self::lastRunAt => $this->lastRunAt,
            self::nextRunAt => $this->nextRunAt,
            self::idleReason => $this->idleReason,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw cron rule payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            agentId: self::optionalString($data, self::agentId),
            name: self::requireString($data, self::name),
            expression: self::requireString($data, self::expression),
            lastRunAt: self::optionalInt($data, self::lastRunAt),
            nextRunAt: self::optionalInt($data, self::nextRunAt),
            idleReason: self::optionalString($data, self::idleReason),
        );
    }
}
