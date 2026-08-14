<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Utils\Logger;

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
     * Applies RT_SYNC_CREATED payload to Hilos::$rt.
     *
     * A row an {@see RtState} refuses never enters the collection: the state is
     * built whole before it is added, so the refusal costs exactly that row. It
     * is caught rather than let out because the worker loop around
     * `handleDaemonMessage()` catches only its two agent exceptions, so an
     * escaping refusal would take the worker down over one broken row.
     *
     * @param RtSyncCreatedSignalData $data Full created state payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     */
    public static function applyCreated(RtSyncCreatedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if (!self::shouldApplyRtSyncRow($data->collectionKey, $data->stateId, $data->row, $skipSelfBroadcastCheck)) {
            return;
        }

        $stateCollection = Hilos::$rt?->getStateCollection($data->collectionKey);
        if ($stateCollection === null) {
            self::applyDiffToStandaloneItem($data->collectionKey, $data->stateId, $data->row);

            return;
        }

        if ($stateCollection->has($data->stateId)) {
            return;
        }

        $stateClass = $stateCollection::STATE_CLASS;
        if (!is_subclass_of($stateClass, RtState::class)) {
            return;
        }

        /** @var class-string<RtState> $stateClass */
        try {
            $state = $stateClass::fromRow($data->row);
        } catch (InvalidFormatException $e) {
            self::logRefusedRow($data->collectionKey, $data->stateId, $e);

            return;
        }

        $stateCollection->add($state);
    }

    /**
     * Applies RT_SYNC_UPDATED payload to an existing RtState row or standalone item.
     *
     * A refused diff is dropped where it broke, and this is NOT a rollback:
     * `applyDiff()` writes field by field, so the fields ahead of the refused one
     * are already on the row when the trap catches it. What the trap does
     * guarantee is that the worker survives — the reason a refused create is
     * caught too, see {@see applyCreated()} — and that the row is not recorded as
     * having accepted the diff, because the baseline is left where it was. The
     * next local `sync()` therefore re-sends those fields instead of treating a
     * half-applied diff as agreed.
     *
     * @param RtSyncUpdatedSignalData $data Diff payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     */
    public static function applyUpdated(RtSyncUpdatedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if (!self::shouldApplyRtSyncRow($data->collectionKey, $data->stateId, $data->row, $skipSelfBroadcastCheck)) {
            return;
        }

        $stateCollection = Hilos::$rt?->getStateCollection($data->collectionKey);
        if ($stateCollection === null) {
            self::applyDiffToStandaloneItem($data->collectionKey, $data->stateId, $data->row);

            return;
        }

        $state = $stateCollection->get($data->stateId);
        if ($state === null) {
            return;
        }

        try {
            $state->applyDiff($data->row);
        } catch (InvalidFormatException $e) {
            self::logRefusedRow($data->collectionKey, $data->stateId, $e);

            return;
        }

        $state->markRtSyncBaseline();
    }

    /**
     * Applies RT_SYNC_DELETED payload by removing state from its collection.
     *
     * @param RtSyncDeletedSignalData $data Deleted state identity from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     */
    public static function applyDeleted(RtSyncDeletedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if (!self::shouldApplyRtSync($data->collectionKey, $data->stateId, $skipSelfBroadcastCheck)) {
            return;
        }

        $stateCollection = Hilos::$rt?->getStateCollection($data->collectionKey);
        if ($stateCollection === null) {
            return;
        }

        if ($stateCollection->has($data->stateId)) {
            $stateCollection->remove($data->stateId);
        }
    }

    /**
     * Returns false when row payload is invalid or this process should skip applying the sync.
     *
     * @param string $collectionKey RT collection key from sync payload
     * @param string $stateId Target state id from sync payload
     * @param array<string, mixed> $row Full state or diff row
     * @param bool $skipSelfBroadcastCheck When true, applies self-broadcast guard from Hilos::$sr
     */
    private static function shouldApplyRtSyncRow(
        string $collectionKey,
        string $stateId,
        array $row,
        bool $skipSelfBroadcastCheck,
    ): bool {
        if ($collectionKey === '' || $stateId === '' || $row === []) {
            return false;
        }

        return self::shouldApplyRtSync($collectionKey, $stateId, $skipSelfBroadcastCheck);
    }

    /**
     * Returns false when identity is invalid or self-broadcast guard says to skip applying.
     *
     * @param string $collectionKey RT collection key from sync payload
     * @param string $stateId Target state id from sync payload
     * @param bool $skipSelfBroadcastCheck When true, applies self-broadcast guard from Hilos::$sr
     */
    private static function shouldApplyRtSync(
        string $collectionKey,
        string $stateId,
        bool $skipSelfBroadcastCheck,
    ): bool {
        if ($collectionKey === '' || $stateId === '') {
            return false;
        }

        if ($skipSelfBroadcastCheck && Hilos::$sr !== null && Hilos::$sr->shouldSkipRtSyncApply($collectionKey, $stateId)) {
            return false;
        }

        return true;
    }

    /**
     * Applies row diff to a standalone RT item when collectionKey resolves to one item, not a collection.
     *
     * @param string $collectionKey RT context key for standalone item lookup
     * @param string $stateId Expected state id of the standalone item
     * @param array<string, mixed> $row Full state or diff row
     */
    private static function applyDiffToStandaloneItem(string $collectionKey, string $stateId, array $row): void
    {
        $state = Hilos::$rt?->getStateItem($collectionKey);
        if ($state === null || $state->getId() !== $stateId) {
            return;
        }

        try {
            $state->applyDiff($row);
        } catch (InvalidFormatException $e) {
            self::logRefusedRow($collectionKey, $stateId, $e);

            return;
        }

        $state->markRtSyncBaseline();
    }

    /**
     * Names the row that was dropped, not just the reason it was: the sender is
     * another process, so the collection key and the row id are the only way to
     * find what stopped arriving here while everything else kept syncing.
     *
     * @param string $collectionKey RT collection key the row belongs to
     * @param string $stateId Id of the row that was refused
     * @param InvalidFormatException $e Refusal raised by the row's own reader
     */
    private static function logRefusedRow(string $collectionKey, string $stateId, InvalidFormatException $e): void
    {
        Logger::warning(
            'RT sync row refused for collection ' . $collectionKey
            . ' id ' . $stateId . ': ' . $e->getMessage(),
        );
    }
}
