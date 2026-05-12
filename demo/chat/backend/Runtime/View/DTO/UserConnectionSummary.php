<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\DTO;

/**
 * Runtime connection projection for one user.
 */
final class UserConnectionSummary
{
    public const string presence = 'presence';
    public const string onlineSessionCount = 'onlineSessionCount';

    public const string PRESENCE_ONLINE = 'online';
    public const string PRESENCE_OFFLINE = 'offline';

    public readonly string $presence;

    /**
     * Creates a runtime summary for one user's active connections.
     *
     * @param int $onlineSessionCount Number of active runtime sessions
     */
    public function __construct(
        public readonly int $onlineSessionCount,
    ) {
        $this->presence = $onlineSessionCount > 0 ? self::PRESENCE_ONLINE : self::PRESENCE_OFFLINE;
    }
}
