<?php

declare(strict_types=1);

namespace Demo\Chat\Auth;

use Demo\Chat\Agents\Hilos\UserAgent;
use Hilos\Auth\AccountOperation\AccountErasurePersonalCaptureResult;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * The chat's part of the person's erasure capture: the feed events the person's renames named,
 * kept by the person's agent ({@see UserAgent}) in the transaction that took its journal off
 * them (HIL-1940). The journal no longer knows them after that, so ChatAgent reads them here to
 * delete the events once the person is gone (HIL-1949).
 *
 * The capture's `project` is exactly `{"renameEvents":[…]}`, the event ids in ascending order,
 * possibly none.
 * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
 */
final class ChatErasureRenameFeed
{
    /** Key of the capture's `project` object that carries the event ids. */
    public const string RENAME_EVENTS = 'renameEvents';

    /**
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param list<int> $eventIds Feed events of the person's renames, in id order
     * @return array<string, list<int>> The capture's `project` keys: `renameEvents` alone
     */
    public static function partOf(array $eventIds): array
    {
        return [self::RENAME_EVENTS => $eventIds];
    }

    /**
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param AccountErasurePersonalCaptureResult $capture Capture the person's agent recorded
     * @return list<int> Feed events of the person's renames, in id order
     * @throws InvalidFormatException When the capture's project part carries no list of event ids
     */
    public static function eventIdsOf(AccountErasurePersonalCaptureResult $capture): array
    {
        $eventIds = $capture->project[self::RENAME_EVENTS] ?? null;
        if (!is_array($eventIds)
            || !array_is_list($eventIds)
            || array_filter($eventIds, static fn (mixed $eventId): bool => !is_int($eventId)) !== []) {
            throw new InvalidFormatException('A chat erasure capture must carry {"renameEvents":[…]}.');
        }

        return $eventIds;
    }
}
