<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\DTO;

use Hilos\Runtime\View\Collection\HilosPresenceSource;

/**
 * Runtime presence summary for one user.
 *
 * The framework presence contract the Hilos users table merges over a DB user:
 * a project's runtime connection source ({@see HilosPresenceSource}) returns this
 * for a user id, and the table projects it into the row's presence fields.
 */
final class HilosUserPresenceSummary
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
