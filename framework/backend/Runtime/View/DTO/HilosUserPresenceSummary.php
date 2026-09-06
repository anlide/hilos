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
     * The count and the freshness answer two different questions and neither stands in for the
     * other: on a cluster the connections of a user can be replicas of another node's, and a
     * broken link leaves the last count served and no longer moving. Saying `online: 2` about a
     * node nobody can hear from is the reading this second member exists to qualify (HIL-800).
     *
     * The default is fresh, so a presence source that knows nothing of clusters keeps the
     * constructor it had and answers the truth for a single node.
     *
     * @param int $onlineSessionCount Number of active runtime sessions
     * @param bool $stale Whether any of those connections is a copy that stopped being updated
     */
    public function __construct(
        public readonly int $onlineSessionCount,
        public readonly bool $stale = false,
    ) {
        $this->presence = $onlineSessionCount > 0 ? self::PRESENCE_ONLINE : self::PRESENCE_OFFLINE;
    }
}
