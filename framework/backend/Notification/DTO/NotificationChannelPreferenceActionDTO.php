<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Notification\NotificationPreferenceAction;

/**
 * NotificationChannelPreferenceActionDTO - payload for the profile_notification_channel_set action.
 *
 * Carries the channel the recipient toggled and the desired state (HIL-485):
 * `enabled = true` opts the channel back in (returns to default), `false` mutes it.
 * The acting user is resolved server-side from the connection, never carried here.
 */
final class NotificationChannelPreferenceActionDTO extends ActionPayloadDTO
{
    /** Payload key: the channel name being toggled. */
    public const string channel = 'channel';

    /** Payload key: the desired state (true = allowed, false = muted). */
    public const string enabled = 'enabled';

    /**
     * @param string $channel Channel name being toggled
     * @param bool $enabled Desired state: true opts in (default), false mutes
     */
    public function __construct(
        public readonly string $channel,
        public readonly bool $enabled,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return NotificationPreferenceAction::CHANNEL_SET;
    }

    /**
     * Whether the payload carries a non-empty channel name.
     *
     * @return bool True when the channel name is present
     */
    public function isValid(): bool
    {
        return $this->channel !== '';
    }

    /**
     * @return array<string, mixed> Data with the channel and desired state
     */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
            self::enabled => $this->enabled,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            // external-boundary: the action payload comes from the browser and isValid() rejects an empty channel
            channel: (string)($inner[self::channel] ?? ''),
            enabled: (bool)($inner[self::enabled] ?? false),
        );
    }
}
