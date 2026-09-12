<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Notification\DeferredNotificationQueue;

/**
 * DeferredRestoreQueue - which of the two restore queues the holder is talking about (HIL-846).
 *
 * Internal to {@see DeferredQueueHandover}: it keys the holder's slot for each queue and names the
 * queue in the holder's log lines. It never travels - each queue has a hand-over name and a
 * receipt of its own on the wire - which is why it is not backed.
 */
enum DeferredRestoreQueue
{
    /** Logins a restore photographed before the swap, held in {@see DeferredSessionCarryoverQueue}. */
    case Sessions;

    /** Letters a restore wrote while nobody could be told, held in {@see DeferredNotificationQueue}. */
    case Notifications;

    /**
     * @return string What the queue holds, as the holder's log lines name it
     */
    public function label(): string
    {
        return match ($this) {
            self::Sessions => 'deferred session carry-over',
            self::Notifications => 'deferred notification',
        };
    }
}
