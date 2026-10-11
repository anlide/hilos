<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One uncataloged dotenv key, with no unmasked value on the wire. */
final class HilosDaemonEnvOrphansTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string key = 'key';
    public const string value = 'value';
    public const string similarTo = 'similarTo';

    /**
     * @param string $rowKey Orphan key used as row address
     * @param string $key Uncataloged dotenv key
     * @param array<string, mixed> $value Masked orphan value
     * @param ?string $similarTo Nearest catalog key within the distance limit
     */
    public function __construct(
        public string $rowKey,
        public string $key,
        public array $value,
        public ?string $similarTo,
    ) {
    }

    /** @return string Orphan key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row address */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Masked orphan payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::key => $this->key,
            self::value => $this->value,
            self::similarTo => $this->similarTo,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw orphan payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            key: self::requireString($data, self::key),
            value: self::requireArray($data, self::value),
            similarTo: self::optionalString($data, self::similarTo),
        );
    }
}
