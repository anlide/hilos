<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Utils\Logger;
use LogicException;
use Throwable;

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
     * built whole before it is added, so the refusal costs exactly that row. It is
     * caught rather than let out because this method runs in the MASTER as well as
     * in a worker. The master reaches it from {@see DaemonManager::handleDaemonSignal()}
     * inside {@see DaemonManager::dispatchSignals()}, which drains the whole queued
     * batch of the tick with no catch of its own: an escaping refusal would abandon
     * the rest of that batch and land in the run loop's `catch (Throwable)`, which
     * takes the node out of the cluster the way SIGTERM would — the cost
     * {@see DaemonManager::applyReHydrateContained()} already names for its own
     * sibling case. In a worker the loop around `handleDaemonMessage()` has contained
     * a `Throwable` since HIL-574, so there the catch here keeps the failure down to
     * one row rather than to one message.
     *
     * The write itself is marked as applied-remote, so the announcement the collection makes
     * repairs the local views and stops there instead of being sent back where it came from.
     *
     * @param RtSyncCreatedSignalData $data Full created state payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     * @throws HilosException Whatever the applied write raises
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

        SourceChangeBus::whileApplyingRemote(static function () use ($stateCollection, $state): void {
            $stateCollection->add($state);
        });
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
     * On the master path a late echo of the protected mode row is dropped before any of that,
     * see {@see self::isStaleProtectedModeEcho()}: nothing is written and the baseline stays.
     *
     * @param RtSyncUpdatedSignalData $data Diff payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     */
    public static function applyUpdated(RtSyncUpdatedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if (!self::shouldApplyRtSyncRow($data->collectionKey, $data->stateId, $data->row, $skipSelfBroadcastCheck)) {
            return;
        }

        if ($skipSelfBroadcastCheck && self::isStaleProtectedModeEcho($data)) {
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
     * Applies RT_SYNC_UPDATED in bounded portions, publishing nothing until the whole draft holds.
     *
     * The row's own draft ({@see RtState::draftRow()}) takes the diff through the row's codec, read
     * field by field off the segmented carrier, and the complete result is restored once more, so
     * the codec's cross-field contracts are judged too. Only then is the draft published into the
     * live row in one swap ({@see RtState::publishDraft()}) and the baseline moved. A refusal leaves
     * every field, the baseline and the previous values where they were and reaches the caller,
     * which therefore has no success to acknowledge. This is the deliberate difference from the
     * synchronous {@see self::applyUpdated()}, which keeps writing field by field.
     *
     * The guards are those of the synchronous door, the late protected mode echo included; the
     * phase is read out of the carrier, not out of a materialized row. A legacy-only row has no
     * draft and keeps the synchronous door.
     *
     * @param RtSyncUpdatedSignalData $data Diff payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     * @return Generator<int, RtWork, int, void> Bounded validation, then one publication
     * @throws Throwable Whatever the row's codec refuses, raised before any field is published
     */
    public static function applyUpdatedInPortions(RtSyncUpdatedSignalData $data, bool $skipSelfBroadcastCheck = true): Generator
    {
        yield new RtWork();
        if ($data->collectionKey === '' || $data->stateId === '' || self::isEmptyCarried($data->carriedRow())
            || !self::shouldApplyRtSync($data->collectionKey, $data->stateId, $skipSelfBroadcastCheck)
            || ($skipSelfBroadcastCheck && self::isStaleProtectedModeEcho($data))) {
            return;
        }

        $stateCollection = Hilos::$rt?->getStateCollection($data->collectionKey);
        $state = $stateCollection === null
            ? Hilos::$rt?->getStateItem($data->collectionKey)
            : $stateCollection->get($data->stateId);
        if ($state === null || ($stateCollection === null && $state->getId() !== $data->stateId)) {
            return;
        }
        if (!$state->hasRowBinding()) {
            self::applyUpdated($data, skipSelfBroadcastCheck: false);

            return;
        }

        $codec = $state->rowCodec();
        $draft = $state->draftRow();
        yield from $codec->patch($draft, $data->rowValues()->reader());
        $out = new RtRowWriter(RtRowArena::process(), RtRowRole::Transient);
        yield from $codec->export($draft, $out);
        $full = yield from $out->finish();
        yield from $codec->restore($full->reader());

        if ($stateCollection === null && $data->collectionKey === StateProtectedModeRuntime::RT_ITEM) {
            Hilos::$ac?->invalidateProtectedMode();
            Hilos::$rt?->noteProtectedModeChanged();
        }
        $state->publishDraft($draft);
        $state->markRtSyncBaseline();
    }

    /**
     * Applies RT_SYNC_DELETED payload by removing state from its collection.
     *
     * Applied-remote for the same reason as {@see self::applyCreated()}: the removal repairs the
     * local views without being announced back to the process that sent it.
     *
     * @param RtSyncDeletedSignalData $data Deleted state identity from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     * @throws HilosException Whatever the applied write raises
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
            SourceChangeBus::whileApplyingRemote(static function () use ($stateCollection, $data): void {
                $stateCollection->remove($data->stateId);
            });
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
     * Whether a frame of the protected mode row would take the phase back: self-echo accounting is
     * one mark per row, so an echo can get here after the row moved on. Stale means both ends of
     * the frame's phase move differ from the row's phase; a phase that is not a string is left to
     * `applyDiff()` to refuse, because a Hilos process does not outlive the warning of printing one.
     *
     * The two phases are read out of the payload in the form it carries them: one field each,
     * never the whole diff.
     *
     * @param RtSyncUpdatedSignalData $data Diff payload the master is about to apply
     * @return bool True when the frame is stale, already logged, and must not be applied
     * @throws InvalidFormatException When segmented values are not a field map
     * @throws LogicException When a segmented lease already closed
     */
    private static function isStaleProtectedModeEcho(RtSyncUpdatedSignalData $data): bool
    {
        if ($data->collectionKey !== StateProtectedModeRuntime::RT_ITEM) {
            return false;
        }

        $previous = self::carriedField($data->carriedPrevious(), StateProtectedModeRuntime::phase);
        $target = self::carriedField($data->carriedRow(), StateProtectedModeRuntime::phase);
        if (!is_string($previous) || !is_string($target)) {
            return false;
        }

        $state = Hilos::$rt?->getStateItem(StateProtectedModeRuntime::RT_ITEM);
        if (!$state instanceof StateProtectedModeRuntime) {
            return false;
        }

        if ($previous === $state->phase || $target === $state->phase) {
            return false;
        }

        Logger::warning(
            "Protected mode: dropped a late echo moving the phase from '{$previous}' to '{$target}' while the row reads '{$state->phase}'",
        );

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

        if ($collectionKey === StateProtectedModeRuntime::RT_ITEM) {
            Hilos::$ac?->invalidateProtectedMode();
            Hilos::$rt?->noteProtectedModeChanged();
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

    /**
     * @param array<string, mixed>|RtRowValues $values Carried row or previous values
     * @param string $field Field to read alone
     * @return mixed Native value of that one field, or null when it is not carried
     * @throws InvalidFormatException When segmented values are not a field map
     * @throws LogicException When a segmented lease already closed
     */
    private static function carriedField(array|RtRowValues $values, string $field): mixed
    {
        return is_array($values) ? $values[$field] ?? null : $values->field($field)?->toNative();
    }

    /**
     * @param array<string, mixed>|RtRowValues $values Carried row
     * @return bool Whether the payload carries no field at all
     * @throws LogicException When a segmented lease already closed
     */
    private static function isEmptyCarried(array|RtRowValues $values): bool
    {
        return is_array($values) ? $values === [] : $values->isEmptyMap();
    }
}
