<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Database\View\Collection\Users as DbUsers;
use Demo\Chat\Database\View\Item\User as DbUser;
use Demo\Chat\Frontend\DTO\FrontendUserConnectionStatsProjection;
use Demo\Chat\Frontend\DTO\FrontendUserPresenceProjection;
use Demo\Chat\Frontend\DTO\FrontendUserProjection;
use Demo\Chat\Hilos;
use Hilos\Core\Router\DTO\FrontendChangesDTO;

/**
 * Builds user-related frontend state projections.
 */
final class UserFrontendStateProjector
{
    /**
     * Builds a full snapshot for a user collection.
     *
     * @param DbUsers $users Users to project
     * @param bool $includeConnectionStats Include runtime session counters
     * @param bool $includePublicUsers Include public user records
     * @return FrontendChangesDTO Full frontend state snapshot
     */
    public static function fullForUsers(
        DbUsers $users,
        bool $includeConnectionStats = false,
        bool $includePublicUsers = true,
    ): FrontendChangesDTO
    {
        return new FrontendChangesDTO(
            full: self::collectionsForUsers(
                $users,
                includePublicUsers: $includePublicUsers,
                includeConnectionStats: $includeConnectionStats,
            ),
        );
    }

    /**
     * Builds a full snapshot for one user.
     *
     * @param DbUser $user User to project
     * @param bool $includeConnectionStats Include runtime session counters
     * @return FrontendChangesDTO Full frontend state snapshot
     */
    public static function fullForUser(DbUser $user, bool $includeConnectionStats = false): FrontendChangesDTO
    {
        return new FrontendChangesDTO(
            full: self::collectionsForUsers(
                [$user],
                includePublicUsers: true,
                includeConnectionStats: $includeConnectionStats,
            ),
        );
    }

    /**
     * Builds an incremental update for one user.
     *
     * @param DbUser $user User to project
     * @param bool $includePublicUser Include public user fields
     * @param bool $includeConnectionStats Include runtime session counters
     * @return FrontendChangesDTO Incremental frontend state update
     */
    public static function updatesForUser(
        DbUser $user,
        bool $includePublicUser = false,
        bool $includeConnectionStats = false,
    ): FrontendChangesDTO {
        return new FrontendChangesDTO(
            updates: self::collectionsForUsers(
                [$user],
                includePublicUsers: $includePublicUser,
                includeConnectionStats: $includeConnectionStats,
            ),
        );
    }

    /**
     * Builds frontend state collections for the given users.
     *
     * @param iterable<DbUser> $users Users to serialize
     * @param bool $includePublicUsers Include public user records
     * @param bool $includeConnectionStats Include runtime session counters
     * @return array<string, list<array<string, mixed>>>
     */
    private static function collectionsForUsers(
        iterable $users,
        bool $includePublicUsers,
        bool $includeConnectionStats,
    ): array {
        $collections = [];

        foreach ($users as $user) {
            $userId = (int) $user->id;
            $summary = Hilos::$rt->connections->summaryForUser($userId);

            if ($includePublicUsers) {
                $collections[FrontendStateCollectionKey::USERS][] = FrontendUserProjection::fromDbUser($user)->toArray();
            }

            $collections[FrontendStateCollectionKey::USER_PRESENCE][] = (new FrontendUserPresenceProjection(
                userId: $userId,
                presence: $summary->presence,
            ))->toArray();

            if ($includeConnectionStats) {
                $collections[FrontendStateCollectionKey::USER_CONNECTION_STATS][] = (new FrontendUserConnectionStatsProjection(
                    userId: $userId,
                    onlineSessionCount: $summary->onlineSessionCount,
                ))->toArray();
            }
        }

        return $collections;
    }
}
