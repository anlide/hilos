<?php

declare(strict_types=1);

namespace Hilos\Push;

use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;

/**
 * PushSubscriptionAction - client → server action names of the profile push toggle (HIL-199).
 *
 * The per-device web-push opt in/out actions, mounted on the profile page (an
 * AUTHENTICATED surface) alongside the per-user channel preferences (HIL-485).
 * Subscribing upserts a {@see ObjectPushSubscriptions}
 * row for the acting device; unsubscribing removes it. Both resolve the acting user
 * server-side from the connection, never the payload, so a client can only ever
 * subscribe its own device. Unlike the channel preference (a per-user state fanned to
 * every connection), a subscription is per-device durable state the toggle reads back
 * from the browser's own getSubscription(), so neither action fans a signal.
 */
final class PushSubscriptionAction
{
    /** Register the acting device's push subscription (payload carries endpoint and keys). */
    public const string SUBSCRIBE = 'push_subscribe';

    /** Remove a device's push subscription (payload carries the endpoint). */
    public const string UNSUBSCRIBE = 'push_unsubscribe';
}
