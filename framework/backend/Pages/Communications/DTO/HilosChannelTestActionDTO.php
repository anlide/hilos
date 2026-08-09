<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DTO for the communications_channel_test action payload (HIL-200).
 *
 * Names the channel to exercise. The handler resolves the acting admin's address for
 * the channel and emits a test notification narrowed to it; the full send path
 * (dispatcher → delivery row → agent → transport) then runs for real.
 */
final class HilosChannelTestActionDTO extends ActionPayloadDTO
{
    /** Payload key: the target channel name. */
    public const string channel = 'channel';

    /**
     * @param string $channel Target channel name
     */
    public function __construct(
        public readonly string $channel,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST;
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
        );
    }

    /**
     * @return array<string, mixed> Data with the channel
     */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
        ];
    }
}
