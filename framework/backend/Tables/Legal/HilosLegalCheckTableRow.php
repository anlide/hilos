<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Legal administration table row and its wire representation. */
final class HilosLegalCheckTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string ok = 'ok';
    public const string items = 'items';

    /**
     * @param string $rowKey Stable row identity
     * @param bool $ok Ok
     * @param list<array<string, mixed>> $items Diagnostic wire details
     */
    public function __construct(
        public string $rowKey,
        public bool $ok,
        public array $items,
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
            self::ok => $this->ok,
            self::items => $this->items,
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
            ok: self::requireBool($data, self::ok),
            items: self::requireArray($data, self::items),
        );
    }
}
