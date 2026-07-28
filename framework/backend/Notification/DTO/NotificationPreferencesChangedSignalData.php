<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * NotificationPreferencesChangedSignalData - server → client payload for NOTIFICATION_PREFERENCES_CHANGED.
 *
 * Multi-device sync of the per-user channel preferences (HIL-485): tells the
 * recipient's other connections the full current channel → allowed map after a
 * toggle. The full set (not a delta) is carried because it is small and a complete
 * snapshot keeps every tab in agreement without reconciling deltas. `true` means
 * the channel is allowed (no muted row), `false` means muted.
 */
final class NotificationPreferencesChangedSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the channel → allowed map. */
    public const string channels = 'channels';

    /**
     * @param array<string, bool> $channels Channel name → allowed (true) / muted (false)
     */
    public function __construct(
        public readonly array $channels,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::channels => $this->channels,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $rawChannels = $data[self::channels] ?? [];
        $channels = [];
        if (is_array($rawChannels)) {
            foreach ($rawChannels as $channel => $allowed) {
                $channels[(string)$channel] = (bool)$allowed;
            }
        }

        return new static(
            channels: $channels,
        );
    }
}
