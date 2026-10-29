<?php

declare(strict_types=1);

namespace Hilos\Tables\ProtectedMode;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One node's public maintenance report; nodeId is also the viewport row key. */
final class HilosMaintenanceNodesTableRow extends AbstractTableRow
{
    public const string nodeId = 'nodeId';
    public const string phase = 'phase';
    public const string quiesced = 'quiesced';
    public const string isLeader = 'isLeader';
    public const string isInitiator = 'isInitiator';
    public const string reportState = 'reportState';

    /**
     * @param string $nodeId Cluster node identity
     * @param ?string $phase Reported phase, null when unknown
     * @param ?bool $quiesced Leader roster acknowledgement, null when unknown or outside the roster
     * @param bool $isLeader Whether this node is the current leader
     * @param bool $isInitiator Whether this node hosts the current initiator
     * @param string $reportState Current, missing or silent report
     */
    public function __construct(
        public string $nodeId,
        public ?string $phase,
        public ?bool $quiesced,
        public bool $isLeader,
        public bool $isInitiator,
        public string $reportState,
    ) {
    }

    /** @return string Stable node identity */
    public function getRowKey(): string
    {
        return $this->nodeId;
    }

    /** @return string Payload field carrying the row key */
    public static function keyField(): string
    {
        return self::nodeId;
    }

    /** @return array<string, string|bool|null> The six public report fields */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::phase => $this->phase,
            self::quiesced => $this->quiesced,
            self::isLeader => $this->isLeader,
            self::isInitiator => $this->isInitiator,
            self::reportState => $this->reportState,
        ];
    }

    /**
     * @param array<string, mixed> $data Validated runtime projection row
     * @return static Typed node report
     * @throws InvalidFormatException When a required field is absent or has an invalid type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            nodeId: self::requireString($data, self::nodeId),
            phase: self::optionalString($data, self::phase),
            quiesced: self::optionalBool($data, self::quiesced),
            isLeader: self::requireBool($data, self::isLeader),
            isInitiator: self::requireBool($data, self::isInitiator),
            reportState: self::requireString($data, self::reportState),
        );
    }
}
