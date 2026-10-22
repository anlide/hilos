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
    public const string PRESENCE_PROBABLY_OFFLINE = 'probably_offline';
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
     * The doubt is a third question again (HIL-1566): sockets that are still open, and so still
     * counted, may all have gone silent, and the person is then probably gone without having
     * closed anything. It moves the presence only and leaves the count its meaning; with nothing
     * counted the person is offline whatever the doubt says. It is not the freshness either: a
     * replica can be fresh and doubted, or frozen and trusted. The default is no doubt, so a
     * presence source that knows nothing of liveness keeps the constructor it had.
     *
     * @param int $onlineSessionCount Number of active runtime sessions
     * @param bool $stale Whether any of those connections is a copy that stopped being updated
     * @param bool $probablyOffline Whether every one of those connections went silent and is doubted
     */
    public function __construct(
        public readonly int $onlineSessionCount,
        public readonly bool $stale = false,
        bool $probablyOffline = false,
    ) {
        $this->presence = match (true) {
            $onlineSessionCount <= 0 => self::PRESENCE_OFFLINE,
            $probablyOffline => self::PRESENCE_PROBABLY_OFFLINE,
            default => self::PRESENCE_ONLINE,
        };
    }
}
