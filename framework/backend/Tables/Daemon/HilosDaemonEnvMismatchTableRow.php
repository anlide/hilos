<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\DaemonSection\ClusterEnvironmentCellState;

/** One compared catalog key and its cells in node-id order. */
final class HilosDaemonEnvMismatchTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string key = 'key';
    public const string kind = 'kind';
    public const string position = 'position';
    public const string sensitive = 'sensitive';
    public const string value = 'value';
    public const string cells = 'cells';

    public const string KIND_DIVERGED = 'diverged';
    public const string KIND_SOURCES_DIFFER = 'sourcesDiffer';

    public const string CELL_NODE_ID = 'nodeId';
    public const string CELL_STATE = 'state';
    public const string CELL_TYPE = 'type';
    public const string CELL_SOURCE = 'source';
    public const string CELL_DIGEST = 'digest';

    /**
     * @param string $rowKey Compared catalog key
     * @param string $key Compared catalog key
     * @param string $kind Verdict bucket
     * @param int $position Zero-based place in the complete verdict set
     * @param bool $sensitive Whether the serving node declares the key sensitive
     * @param ?array<string, mixed> $value Serving node's masked value, if eligible
     * @param list<array<string, mixed>> $cells Per-node comparison cells
     */
    public function __construct(
        public string $rowKey,
        public string $key,
        public string $kind,
        public int $position,
        public bool $sensitive,
        public ?array $value,
        public array $cells,
    ) {
    }

    /** @return string Stable catalog key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Compared key payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::key => $this->key,
            self::kind => $this->kind,
            self::position => $this->position,
            self::sensitive => $this->sensitive,
            self::value => $this->value,
            self::cells => $this->cells,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw compared key payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a field or cell is malformed
     */
    public static function fromArray(array $data): static
    {
        $kind = self::requireString($data, self::kind);
        $position = self::requireInt($data, self::position);
        if (!in_array($kind, [self::KIND_DIVERGED, self::KIND_SOURCES_DIFFER], true) || $position < 0) {
            throw new InvalidFormatException('Environment mismatch row has an invalid kind or position');
        }

        return new static(
            rowKey: self::requireString($data, self::rowKey),
            key: self::requireString($data, self::key),
            kind: $kind,
            position: $position,
            sensitive: self::requireBool($data, self::sensitive),
            value: self::requireNullableArray($data, self::value),
            cells: self::cellList($data),
        );
    }

    /**
     * @param array<string, mixed> $data Raw compared key payload
     * @return list<array<string, mixed>> Validated cells
     * @throws InvalidFormatException When the list or a cell is malformed
     */
    private static function cellList(array $data): array
    {
        $entries = self::requireArray($data, self::cells);
        if (!array_is_list($entries)) {
            throw new InvalidFormatException('Environment mismatch cells must be a list');
        }

        $cells = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new InvalidFormatException('Environment mismatch row contains an invalid cell');
            }
            $nodeId = self::requireString($entry, self::CELL_NODE_ID);
            $state = self::requireString($entry, self::CELL_STATE);
            if (ClusterEnvironmentCellState::tryFrom($state) === null) {
                throw new InvalidFormatException('Environment mismatch cell has an invalid state');
            }
            $cells[] = [
                self::CELL_NODE_ID => $nodeId,
                self::CELL_STATE => $state,
                self::CELL_TYPE => self::nullableString($entry, self::CELL_TYPE),
                self::CELL_SOURCE => self::nullableString($entry, self::CELL_SOURCE),
                self::CELL_DIGEST => self::nullableString($entry, self::CELL_DIGEST),
            ];
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $data Raw cell
     * @param string $field Required field that may be null
     * @return ?string Field value
     * @throws InvalidFormatException When the field is missing or has another type
     */
    private static function nullableString(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || ($data[$field] !== null && !is_string($data[$field]))) {
            throw new InvalidFormatException('Environment mismatch cell has an invalid ' . $field);
        }

        return $data[$field];
    }
}
