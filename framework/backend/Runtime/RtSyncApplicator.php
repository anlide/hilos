<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Hilos\Hilos;
use Hilos\Runtime\State\Item\RtState;

/**
 * Applies RT sync signals (created/updated/deleted) to Hilos::$rt.
 *
 * Used by DaemonManager and WorkerManager to sync in-memory RT state
 * when receiving RT_SYNC signals from other processes.
 * Analogous to DbSyncApplicator for database sync.
 */
final class RtSyncApplicator
{
    /**
     * Apply RT sync created: create RtState from row and add to state collection.
     *
     * @param array<string, mixed> $signalData collectionKey, stateId, row
     */
    public static function applyCreated(array $signalData): void
    {
        $collectionKey = $signalData['collectionKey'] ?? '';
        $stateId = $signalData['stateId'] ?? '';
        $row = $signalData['row'] ?? [];

        if ($collectionKey === '' || $stateId === '' || $row === []) {
            return;
        }

        if (Hilos::$sr !== null && Hilos::$sr->shouldSkipRtSyncApply($collectionKey, $stateId)) {
            return;
        }

        $stateCollection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($stateCollection === null) {
            return;
        }

        if ($stateCollection->has($stateId)) {
            return;
        }

        $stateClass = $stateCollection::STATE_CLASS;
        if ($stateClass === '' || !is_subclass_of($stateClass, RtState::class)) {
            return;
        }

        /** @var class-string<RtState> $stateClass */
        $state = $stateClass::fromRow($row);
        $stateCollection->add($state);
    }

    /**
     * Apply RT sync updated: find RtState by stateId, apply diff.
     *
     * @param array<string, mixed> $signalData collectionKey, stateId, row (diff)
     */
    public static function applyUpdated(array $signalData): void
    {
        $collectionKey = $signalData['collectionKey'] ?? '';
        $stateId = $signalData['stateId'] ?? '';
        $row = $signalData['row'] ?? [];

        if ($collectionKey === '' || $stateId === '' || $row === []) {
            return;
        }

        if (Hilos::$sr !== null && Hilos::$sr->shouldSkipRtSyncApply($collectionKey, $stateId)) {
            return;
        }

        $stateCollection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($stateCollection === null) {
            return;
        }

        $state = $stateCollection->get($stateId);
        if ($state === null) {
            return;
        }

        $state->applyDiff($row);
        $state->markRtSyncBaseline();
    }

    /**
     * Apply RT sync deleted: remove RtState by stateId from collection.
     *
     * @param array<string, mixed> $signalData collectionKey, stateId
     */
    public static function applyDeleted(array $signalData): void
    {
        $collectionKey = $signalData['collectionKey'] ?? '';
        $stateId = $signalData['stateId'] ?? '';

        if ($collectionKey === '' || $stateId === '') {
            return;
        }

        if (Hilos::$sr !== null && Hilos::$sr->shouldSkipRtSyncApply($collectionKey, $stateId)) {
            return;
        }

        $stateCollection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($stateCollection === null) {
            return;
        }

        if ($stateCollection->has($stateId)) {
            $stateCollection->remove($stateId);
        }
    }
}
