<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the communications_channel_reset action payload (HIL-200).
 *
 * Names one channel and one field to reset to its env/default value. The handler
 * clears the settings override for the field, so the resolver falls back to env, then
 * the descriptor default.
 */
final class HilosChannelSettingResetActionDTO extends ActionPayloadDTO
{
    /** Payload key: the target channel name. */
    public const string channel = 'channel';

    /** Payload key: the target field key. */
    public const string field = 'field';

    /**
     * @param string $channel Target channel name
     * @param string $field Target field key
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $field,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            channel: trim(self::requireString($inner, self::channel)),
            field: trim(self::requireString($inner, self::field)),
        );
    }

    /**
     * @return array<string, mixed> Data with the channel and field
     */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
            self::field => $this->field,
        ];
    }
}
