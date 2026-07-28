<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/**
 * DeliveryChannelSettings - the settings-key convention for channel global enablement (HIL-196).
 *
 * Each delivery channel is globally switched on/off by a boolean setting keyed by
 * its name: `notifications.channel.<name>.enabled`. A channel leaf (email 197, sms
 * 285, push 199, telegram 198) adds the concrete catalog entry under this key; the
 * admin channel-config page (HIL-200) reads and writes it. This helper is the one
 * place the key is derived, so the dispatcher and the channel leaves never spell it
 * out by hand.
 */
final class DeliveryChannelSettings
{
    /** Prefix of the per-channel enablement setting key. */
    public const string PREFIX = 'notifications.channel.';

    /** Suffix of the per-channel enablement setting key. */
    public const string ENABLED_SUFFIX = '.enabled';

    /**
     * Builds the global-enablement setting key for a channel.
     *
     * @param string $channel Channel name
     * @return string Setting key `notifications.channel.<name>.enabled`
     */
    public static function enabledKey(string $channel): string
    {
        return self::PREFIX . $channel . self::ENABLED_SUFFIX;
    }
}
