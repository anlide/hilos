<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

/**
 * DeferredSessionCarryoverBatch - one batch of queued logins taken out for their owner (HIL-846).
 *
 * What {@see DeferredSessionCarryoverQueue::take()} hands the holder of the queue: the sessions of
 * one file set aside, and the id that file is named by. The id is how the owner's receipt finds
 * its way back to exactly this file ({@see DeferredSessionCarryoverQueue::release()}), and it is
 * part of the file name rather than of any process's memory, so a holder that restarted offers the
 * same batch under the same id.
 */
final class DeferredSessionCarryoverBatch
{
    /**
     * @param string $batch Id the batch's file is named by
     * @param list<SessionCarryover> $sessions Sessions of the batch, in the order they were queued
     */
    public function __construct(
        public readonly string $batch,
        public readonly array $sessions,
    ) {
    }
}
