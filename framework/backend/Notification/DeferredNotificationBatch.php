<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * DeferredNotificationBatch - one batch of queued notices taken out for their sender (HIL-846).
 *
 * What {@see DeferredNotificationQueue::take()} hands the holder of the queue: the drafts of one
 * file set aside, and the id that file is named by. The id is how the library's receipt finds its
 * way back to exactly this file ({@see DeferredNotificationQueue::release()}), and it is part of
 * the file name rather than of any process's memory, so a holder that restarted offers the same
 * batch under the same id.
 */
final class DeferredNotificationBatch
{
    /**
     * @param string $batch Id the batch's file is named by
     * @param list<NotificationDraft> $drafts Drafts of the batch, in the order they were queued
     */
    public function __construct(
        public readonly string $batch,
        public readonly array $drafts,
    ) {
    }
}
