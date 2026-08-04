<?php

declare(strict_types=1);

namespace Hilos\Tables\Communications;

use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Notification\Delivery\ChannelConfigField;

/**
 * Backend row payload for the framework channel-config fields table (HIL-200).
 *
 * One row per config field of every registered channel; the channel page renders
 * the rows whose {@see channel} matches its route. A row projects a
 * {@see ChannelConfigField} together with its resolved
 * effective value and source: {@see value} is null for a {@see secret} field (a
 * secret is never sent to the browser — its {@see valueSource} carries only the
 * set/not-set state) and {@see editable} is false for it. The identity rides
 * {@see rowKey}, the field's globally-unique setting key, never a field named `id`
 * that the frontend normalizer would treat as an entity fragment.
 */
final class HilosCommunicationsChannelFieldsTableRow extends AbstractTableRow
{
    /**
     * Payload key of the row identity: the field's settings key.
     *
     * It rides the row fragment's row key, never a field named `id`: a slot payload
     * carrying `id` is ingested by the frontend normalizer as an entity fragment and
     * replaced with a reference, which would strip every other field off this row.
     */
    public const string rowKey = 'rowKey';

    public const string channel = 'channel';
    public const string field = 'field';
    public const string label = 'label';
    public const string type = 'type';
    public const string value = 'value';
    public const string valueSource = 'valueSource';
    public const string secret = 'secret';
    public const string editable = 'editable';

    /**
     * @param string $rowKey Field settings key (row key, globally unique)
     * @param string $channel Owning channel name
     * @param string $field Field key
     * @param string $label Human field label
     * @param string $type Field value type (see SettingsCatalogConstants::TYPE_*)
     * @param bool|float|int|string|null $value Effective value, or null for a secret field
     * @param string $valueSource Where the effective value came from (settings|env|default)
     * @param bool $secret Whether the field is an env-only secret
     * @param bool $editable Whether the field can be edited in the admin (false for a secret)
     */
    public function __construct(
        public string $rowKey,
        public string $channel,
        public string $field,
        public string $label,
        public string $type,
        public bool|float|int|string|null $value,
        public string $valueSource,
        public bool $secret,
        public bool $editable,
    ) {
    }

    /**
     * Returns the stable table row key (the field settings key).
     *
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /**
     * Serializes the row to the fields table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::channel => $this->channel,
            self::field => $this->field,
            self::label => $this->label,
            self::type => $this->type,
            self::value => $this->value,
            self::valueSource => $this->valueSource,
            self::secret => $this->secret,
            self::editable => $this->editable,
        ];
    }

    /**
     * Builds a fields row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed fields table row
     */
    public static function fromArray(array $data): static
    {
        $value = $data[self::value] ?? null;

        return new static(
            rowKey: (string) ($data[self::rowKey] ?? ''),
            channel: (string) ($data[self::channel] ?? ''),
            field: (string) ($data[self::field] ?? ''),
            label: (string) ($data[self::label] ?? ''),
            type: (string) ($data[self::type] ?? ''),
            value: is_scalar($value) ? $value : null,
            valueSource: (string) ($data[self::valueSource] ?? ''),
            secret: (bool) ($data[self::secret] ?? false),
            editable: (bool) ($data[self::editable] ?? false),
        );
    }
}
