<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Subscriber;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;

/**
 * Broadcasts a runtime membership change to the other processes.
 *
 * A subscriber rather than part of the bus itself, because sending is one reaction to a fact
 * among several and it is the only one that must not fire on a change this process merely
 * applied - a rebroadcast echo would circle between processes forever.
 *
 * Runtime only. On the database side the network is driven by the persisted write, where the
 * membership of the mirror and the row reaching storage are separate events: a project may drop
 * a stale object from its collection with nothing to send at all.
 */
final class OutboundRtSyncSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * Queues the RT sync signal for a runtime row this process created or deleted.
     *
     * An update is not queued here: the wire carries the diff, which the store never sees, so
     * that signal stays with the caller that knows it.
     *
     * With no signal router mounted the announcement is a silent no-op, exactly as the direct
     * queueing it replaces was.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @param SourceChangeProvenance $provenance Whether this process authored the write
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (!$change->isRt() || $provenance !== SourceChangeProvenance::LocalWrite) {
            return;
        }

        match ($change->mutationType) {
            TableMutationType::Create => $this->queueCreated($change),
            TableMutationType::Delete => $this->queueDeleted($change),
            default => null,
        };
    }

    /**
     * Queues RT_SYNC_CREATED carrying the full row of the new runtime state.
     *
     * @param SourceChange $change Runtime create fact
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function queueCreated(SourceChange $change): void
    {
        Hilos::$sr?->queueRtSyncSignal(
            SignalConstants::RT_SYNC_CREATED,
            new RtSyncCreatedSignalData(
                $change->sourceKey,
                $change->sourceId,
                $change->row,
                $change->origin,
                $change->originRequestId,
            ),
        );
    }

    /**
     * Queues RT_SYNC_DELETED carrying the row the runtime state held before it left.
     *
     * @param SourceChange $change Runtime delete fact
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function queueDeleted(SourceChange $change): void
    {
        Hilos::$sr?->queueRtSyncSignal(
            SignalConstants::RT_SYNC_DELETED,
            new RtSyncDeletedSignalData(
                $change->sourceKey,
                $change->sourceId,
                $change->row,
                $change->origin,
                $change->originRequestId,
            ),
        );
    }
}
