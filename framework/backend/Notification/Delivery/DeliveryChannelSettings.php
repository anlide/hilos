<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/**
 * DeliveryChannelSettings - the settings-key convention for channel global enablement (HIL-196).
 *
 * Each delivery channel is globally switched on/off by a boolean setting keyed by
 * its name: `notifications.channel.<name>.enabled`, and each of its operational
 * config fields (HIL-200) is overridden by a setting keyed
 * `notifications.channel.<name>.<field>`. A channel leaf (email 197, sms 285, push
 * 199, telegram 198) declares the fields; the admin channel-config page reads and
 * writes these keys. This helper is the one place the keys are derived, so the
 * dispatcher, the resolver, and the channel leaves never spell them out by hand.
 */
final class DeliveryChannelSettings
{
    /** Prefix of the per-channel setting keys. */
    public const string PREFIX = 'notifications.channel.';

    /** Separator between the channel name and a sub-key. */
    public const string SEPARATOR = '.';

    /** Suffix of the per-channel enablement setting key. */
    public const string ENABLED_SUFFIX = '.enabled';

    /**
     * The pseudo-field name the channel-set action uses for the enablement toggle.
     *
     * `enabled` is not a config field; it is the global boolean switch. The hub's
     * toggle rides the shared set action with this field name, and the handler writes
     * {@see enabledKey()} — note {@see fieldKey()} with this name yields the same key.
     */
    public const string ENABLED_FIELD = 'enabled';

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

    /**
     * Builds the settings-override key for one channel config field.
     *
     * @param string $channel Channel name
     * @param string $field Field key
     * @return string Setting key `notifications.channel.<name>.<field>`
     */
    public static function fieldKey(string $channel, string $field): string
    {
        return self::PREFIX . $channel . self::SEPARATOR . $field;
    }
}
