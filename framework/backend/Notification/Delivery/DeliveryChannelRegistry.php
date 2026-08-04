<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Hilos;

/**
 * DeliveryChannelRegistry - the code-side catalog of dispatchable channels (HIL-196).
 *
 * Maps a channel name to its {@see AbstractDeliveryChannel} descriptor. The
 * framework ships this empty base (no built-in channels); a project points
 * {@see Hilos::NOTIFICATION_CHANNEL_REGISTRY} at its own subclass and adds
 * channels by overriding {@see channels()} with `array_replace(parent::channels(),
 * [...])`, so channel leaves (email 197, sms 285, push 199, telegram 198) compose
 * without editing a central list.
 *
 * The dispatcher reads it through {@see all()} to fan a notification; an empty
 * registry makes the dispatcher a no-op, which is the framework default.
 */
abstract class DeliveryChannelRegistry
{
    /**
     * The channel descriptors keyed by channel name.
     *
     * A project overrides this and merges its own entries onto the parent's:
     *
     * ```php
     * protected static function channels(): array
     * {
     *     return array_replace(parent::channels(), [
     *         'email' => new EmailDeliveryChannel(),
     *     ]);
     * }
     * ```
     *
     * @return array<string, AbstractDeliveryChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return [];
    }

    /**
     * Returns every registered channel descriptor keyed by name.
     *
     * @return array<string, AbstractDeliveryChannel> Channel descriptors keyed by name
     */
    public static function all(): array
    {
        return static::channels();
    }

    /**
     * Returns one channel descriptor by name, or null when it is not registered.
     *
     * @param string $channel Channel name
     * @return ?AbstractDeliveryChannel Descriptor, or null when unknown
     */
    public static function get(string $channel): ?AbstractDeliveryChannel
    {
        return static::channels()[$channel] ?? null;
    }
}
