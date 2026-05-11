<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Util;

use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Builds user presence delivery payloads from connection source changes.
 */
final class UserPresenceDeliveryBuilder
{
    /**
     * Builds a presence payload for a connection change that affects a user.
     *
     * Update diffs without a userId are ignored because they cannot change the
     * user presence aggregate. A null return means there is no frontend payload
     * to deliver for the change.
     *
     * @param SourceChange $change Connection RT source change
     * @param bool $includeConnectionStats Whether to include online session counters
     * @return ?UserPresenceSignalData Presence update payload, or null when no user is affected
     */
    public static function buildForConnectionChange(
        SourceChange $change,
        bool $includeConnectionStats,
    ): ?UserPresenceSignalData {
        if (
            $change->mutationType === TableMutationType::Update
            && !array_key_exists(StateConnection::userId, $change->row)
        ) {
            return null;
        }

        $userId = self::userIdFromConnectionChange($change);
        if ($userId <= 0 || Hilos::$db === null) {
            return null;
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return null;
        }

        return UserPresenceSignalData::fromFrontendChanges(
            UserFrontendStateProjector::updatesForUser($user, includeConnectionStats: $includeConnectionStats),
        );
    }

    /**
     * Extracts the affected user id from a connection RT change.
     *
     * Falls back to the live RT row when an update diff does not include
     * `userId`. Delete changes rely on the previous row carried in the source
     * change payload.
     *
     * @param SourceChange $change Connection RT source change
     * @return int Affected user id, or 0 when it cannot be resolved
     */
    public static function userIdFromConnectionChange(SourceChange $change): int
    {
        $userId = (int)($change->row[StateConnection::userId] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        if (Hilos::$rt === null) {
            return 0;
        }

        return (int)(Hilos::$rt->connections[$change->sourceId]?->userId ?? 0);
    }
}
