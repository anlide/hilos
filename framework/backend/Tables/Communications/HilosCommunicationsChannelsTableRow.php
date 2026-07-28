<?php

declare(strict_types=1);

namespace Hilos\Tables\Communications;

use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the framework communications channels hub table (HIL-200).
 *
 * One row per registered delivery channel, projected from the channel descriptor
 * and its resolved config: whether the channel is globally enabled, whether every
 * config field resolved to a value ({@see configured} / {@see missingFields}), and
 * its transport {@see driver}. The row identity rides {@see channel}, the channel
 * name — never a field named `id`, which the frontend normalizer would treat as an
 * entity fragment and strip the row ({@see AbstractTableRow}).
 */
final class HilosCommunicationsChannelsTableRow extends AbstractTableRow
{
    /**
     * Payload key of the row identity: the channel name.
     *
     * It rides the row fragment's row key, never a field named `id`: a slot payload
     * carrying `id` is ingested by the frontend normalizer as an entity fragment and
     * replaced with a reference, which would strip every other field off this row.
     */
    public const string channel = 'channel';

    public const string label = 'label';
    public const string enabled = 'enabled';
    public const string configured = 'configured';
    public const string driver = 'driver';
    public const string missingFields = 'missingFields';

    /**
     * @param string $channel Channel name (registry key and row key)
     * @param string $label Human channel label
     * @param bool $enabled Whether the channel is globally enabled
     * @param bool $configured Whether every config field resolved to a value
     * @param string $driver Transport/driver name
     * @param int $missingFields Count of config fields that did not resolve (source default)
     */
    public function __construct(
        public string $channel,
        public string $label,
        public bool $enabled,
        public bool $configured,
        public string $driver,
        public int $missingFields,
    ) {
    }

    /**
     * Returns the stable table row key (the channel name).
     *
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->channel;
    }

    /**
     * Serializes the row to the channels table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
            self::label => $this->label,
            self::enabled => $this->enabled,
            self::configured => $this->configured,
            self::driver => $this->driver,
            self::missingFields => $this->missingFields,
        ];
    }

    /**
     * Builds a channels row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed channels table row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            channel: (string) ($data[self::channel] ?? ''),
            label: (string) ($data[self::label] ?? ''),
            enabled: (bool) ($data[self::enabled] ?? false),
            configured: (bool) ($data[self::configured] ?? false),
            driver: (string) ($data[self::driver] ?? ''),
            missingFields: (int) ($data[self::missingFields] ?? 0),
        );
    }
}
