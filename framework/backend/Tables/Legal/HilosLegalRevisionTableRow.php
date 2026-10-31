<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Legal administration table row and its wire representation. */
final class HilosLegalRevisionTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string declared = 'declared';
    public const string revision = 'revision';
    public const string current = 'current';
    public const string origin = 'origin';
    public const string heldCount = 'heldCount';
    public const string acceptedCount = 'acceptedCount';
    public const string publishedOn = 'publishedOn';
    public const string effectiveOn = 'effectiveOn';

    /**
     * @param string $rowKey Stable row identity
     * @param bool $declared Declared
     * @param ?array<string, mixed> $revision Declared revision metadata
     * @param bool $current Current
     * @param ?string $origin Origin
     * @param int $heldCount HeldCount
     * @param int $acceptedCount AcceptedCount
     * @param ?string $publishedOn Declared publication date, YYYY-MM-DD
     * @param ?string $effectiveOn Declared acceptance deadline, YYYY-MM-DD
     */
    public function __construct(
        public string $rowKey,
        public bool $declared,
        public ?array $revision,
        public bool $current,
        public ?string $origin,
        public int $heldCount,
        public int $acceptedCount,
        public ?string $publishedOn,
        public ?string $effectiveOn,
    ) {
    }

    /** @return string Stable row identity */
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
            self::declared => $this->declared,
            self::revision => $this->revision,
            self::current => $this->current,
            self::origin => $this->origin,
            self::heldCount => $this->heldCount,
            self::acceptedCount => $this->acceptedCount,
            self::publishedOn => $this->publishedOn,
            self::effectiveOn => $this->effectiveOn,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            declared: self::requireBool($data, self::declared),
            revision: self::optionalArray($data, self::revision),
            current: self::requireBool($data, self::current),
            origin: self::optionalString($data, self::origin),
            heldCount: self::requireInt($data, self::heldCount),
            acceptedCount: self::requireInt($data, self::acceptedCount),
            publishedOn: self::optionalString($data, self::publishedOn),
            effectiveOn: self::optionalString($data, self::effectiveOn),
        );
    }
}
