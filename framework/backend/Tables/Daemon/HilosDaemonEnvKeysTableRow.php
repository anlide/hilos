<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One catalog key in the masked reading of a node's environment. */
final class HilosDaemonEnvKeysTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string key = 'key';
    public const string type = 'type';
    public const string required = 'required';
    public const string declaredBy = 'declaredBy';
    public const string sensitive = 'sensitive';
    public const string perNode = 'perNode';
    public const string source = 'source';
    public const string value = 'value';
    public const string disk = 'disk';
    public const string valueText = 'valueText';

    /**
     * @param string $rowKey Catalog key used as row address
     * @param string $key Catalog key
     * @param string $type Declared value type
     * @param bool $required Whether a value is mandatory
     * @param string $declaredBy Framework or project
     * @param bool $sensitive Whether the value is secret
     * @param bool $perNode Whether values may differ between nodes
     * @param string $source Source chosen by the process
     * @param array<string, mixed> $value Masked process value
     * @param ?array<string, mixed> $disk Masked divergent disk value
     * @param ?string $valueText Searchable visible text, if any
     */
    public function __construct(
        public string $rowKey,
        public string $key,
        public string $type,
        public bool $required,
        public string $declaredBy,
        public bool $sensitive,
        public bool $perNode,
        public string $source,
        public array $value,
        public ?array $disk,
        public ?string $valueText,
    ) {
    }

    /** @return string Catalog key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row address */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Masked catalog key payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::key => $this->key,
            self::type => $this->type,
            self::required => $this->required,
            self::declaredBy => $this->declaredBy,
            self::sensitive => $this->sensitive,
            self::perNode => $this->perNode,
            self::source => $this->source,
            self::value => $this->value,
            self::disk => $this->disk,
            self::valueText => $this->valueText,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw catalog key payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            key: self::requireString($data, self::key),
            type: self::requireString($data, self::type),
            required: self::requireBool($data, self::required),
            declaredBy: self::requireString($data, self::declaredBy),
            sensitive: self::requireBool($data, self::sensitive),
            perNode: self::requireBool($data, self::perNode),
            source: self::requireString($data, self::source),
            value: self::requireArray($data, self::value),
            disk: self::requireNullableArray($data, self::disk),
            valueText: self::optionalString($data, self::valueText),
        );
    }
}
