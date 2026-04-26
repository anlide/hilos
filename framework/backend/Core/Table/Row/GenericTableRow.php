<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Row;

/**
 * Generic fallback row used when a table does not provide its own row class.
 */
final class GenericTableRow extends AbstractTableRow
{
    public const string FIELD_ID = 'id';

    public const string FIELD_KEY = 'key';

    /**
     * @param array<string, mixed> $data Row payload
     */
    public function __construct(
        private array $data,
    ) {
    }

    /**
     * Returns the conventional row key from the generic payload.
     *
     * Uses `id` first, then `key`, and falls back to `null` when neither field
     * is a scalar row key.
     */
    public function getRowKey(): string|int|null
    {
        $key = $this->data[self::FIELD_ID] ?? $this->data[self::FIELD_KEY] ?? null;

        return is_string($key) || is_int($key) ? $key : null;
    }

    /**
     * Serializes the generic row back to the raw table payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Rebuilds a generic row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     */
    public static function fromArray(array $data): static
    {
        return new self($data);
    }
}
