<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

/**
 * NotificationChannelState - one channel's row in the profile notification section (HIL-485).
 *
 * The profile section lists every globally enabled channel (HIL-200) the
 * recipient could receive on, including channels they have no address for yet:
 * unlike the {@see NotificationPreferencesChangedSignalData} multi-device map
 * (togglable channels only), the section shows a no-address channel too, disabled
 * with an "add an address" hint. Each row carries the channel's name, its human
 * label, whether the recipient currently allows it (sparse opt-out: no muted row =
 * allowed), and whether they have a resolvable address for it.
 */
final class NotificationChannelState
{
    /** Payload key: the channel name (its registry key and toggle target). */
    public const string channel = 'channel';

    /** Payload key: the channel's human label. */
    public const string label = 'label';

    /** Payload key: whether the recipient currently allows the channel. */
    public const string allowed = 'allowed';

    /** Payload key: whether the recipient has a resolvable address for the channel. */
    public const string hasAddress = 'hasAddress';

    /**
     * @param string $channel Channel name (registry key and toggle target)
     * @param string $label Channel's human label
     * @param bool $allowed Whether the recipient currently allows the channel
     * @param bool $hasAddress Whether the recipient has a resolvable address for the channel
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $label,
        public readonly bool $allowed,
        public readonly bool $hasAddress,
    ) {
    }

    /**
     * @return array<string, mixed> Channel row as a wire payload
     */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
            self::label => $this->label,
            self::allowed => $this->allowed,
            self::hasAddress => $this->hasAddress,
        ];
    }
}
