<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One project deviation, retaining the adopted standard statement and clause order. */
final class HilosLegalDocumentDeviationTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string clauseKey = 'clauseKey';
    public const string standardStatement = 'standardStatement';
    public const string statement = 'statement';
    public const string text = 'text';
    public const string direction = 'direction';
    public const string position = 'position';

    /**
     * @param string $rowKey Stable clause identity
     * @param string $clauseKey Standard clause key
     * @param string $standardStatement Adopted standard statement
     * @param string $statement Project statement
     * @param string $text Full project clause text
     * @param string $direction Declared stricter or looser direction
     * @param int $position Zero-based position in the adopted set
     */
    public function __construct(
        public string $rowKey,
        public string $clauseKey,
        public string $standardStatement,
        public string $statement,
        public string $text,
        public string $direction,
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
            self::standardStatement => $this->standardStatement,
            self::statement => $this->statement,
            self::text => $this->text,
            self::direction => $this->direction,
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
            standardStatement: self::requireString($data, self::standardStatement),
            statement: self::requireString($data, self::statement),
            text: self::requireString($data, self::text),
            direction: self::requireString($data, self::direction),
            position: self::requireInt($data, self::position),
        );
    }
}
