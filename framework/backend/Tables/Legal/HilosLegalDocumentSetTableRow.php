<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One clause of the standard set adopted by the current project revision. */
final class HilosLegalDocumentSetTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string clauseKey = 'clauseKey';
    public const string statement = 'statement';
    public const string position = 'position';

    /**
     * @param string $rowKey Stable clause identity
     * @param string $clauseKey Standard clause key
     * @param string $statement Standard clause statement
     * @param int $position Zero-based position in the adopted set
     */
    public function __construct(
        public string $rowKey,
        public string $clauseKey,
        public string $statement,
        public int $position,
    ) {
    }

    /** @return string Stable clause identity */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Identity field in the wire payload */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Serialized table row */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::clauseKey => $this->clauseKey,
            self::statement => $this->statement,
            self::position => $this->position,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            clauseKey: self::requireString($data, self::clauseKey),
            statement: self::requireString($data, self::statement),
            position: self::requireInt($data, self::position),
        );
    }
}
