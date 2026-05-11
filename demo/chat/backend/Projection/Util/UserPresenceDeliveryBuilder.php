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
 * Shared helper that turns a connection {@see SourceChange} into a
 * {@see UserPresenceSignalData} payload, or returns null when the change is
 * irrelevant (no user binding affected).
 */
final class UserPresenceDeliveryBuilder
{
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
     * Extracts the affected user id from a connection RT change. Falls back to
     * the live RT row when the diff did not include `userId`.
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
