<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Notification\Delivery\DeliveryChannelSettings;

/**
 * DTO for the communications_channel_set action payload (HIL-200).
 *
 * Names one channel, one field, and the new value to persist as a settings override.
 * The hub's enablement toggle rides this same action with the `enabled` field
 * ({@see DeliveryChannelSettings::ENABLED_FIELD}), so a
 * single owning page handles both surfaces. The value is left untyped here; the handler
 * coerces and validates it against the field descriptor before writing.
 */
final class HilosChannelSettingUpdateActionDTO extends ActionPayloadDTO
{
    /** Payload key: the target channel name. */
    public const string channel = 'channel';

    /** Payload key: the target field key (or `enabled` for the global toggle). */
    public const string field = 'field';

    /** Payload key: the new value. */
    public const string value = 'value';

    /**
     * @param string $channel Target channel name
     * @param string $field Target field key
     * @param mixed $value New value
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $field,
        public readonly mixed $value,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            channel: is_string($inner[self::channel] ?? null) ? trim($inner[self::channel]) : '',
            field: is_string($inner[self::field] ?? null) ? trim($inner[self::field]) : '',
            value: $inner[self::value] ?? null,
        );
    }

    /**
     * @return array<string, mixed> Data with the channel, field, and value
     */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
            self::field => $this->field,
            self::value => $this->value,
        ];
    }
}
