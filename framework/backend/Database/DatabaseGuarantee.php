<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Cluster\Peer\PeerMarkers;
use Hilos\Hilos;

/**
 * What a project promises about its database, so that every node may rely on it (HIL-1206).
 *
 * Two mechanisms rest on these promises and could not work without them. A node that announces a
 * fact leaves its neighbours to read the row back from the shared database rather than carrying a
 * copy over the wire (HIL-670); an entity is read by processes other than its owner (HIL-631).
 * Both are correct only if every node and process resolves each logical database name to the same
 * logical database, and a read through that name returns writes committed to it. Different logical
 * names may resolve to databases on different physical servers. The project states that it keeps
 * both promises for every name in {@see Hilos::DATABASE_GUARANTEES}; a node whose project did not
 * state them refuses to start instead of reading wrong rows quietly.
 *
 * Only the first promise for the primary database is checked: in a cluster every node names the
 * primary marker on the peer handshake, and a node reading another marker is admitted by nobody
 * ({@see PeerMarkers}).
 * Registered secondary database topology is not sent or checked by that handshake. The second
 * promise is only declared, for each name. A lagging replica cannot be caught by a probe - one
 * that caught up by the time of the probe passes it - and a check of the server's own setting would
 * refuse a correct configuration: Galera behind a proxy that sends everything to one server keeps
 * the promise with the setting off.
 */
enum DatabaseGuarantee: string
{
    /** Every node and process resolves each logical database name to the same logical database. */
    case ONE_LOGICAL_DATABASE = 'one_logical_database';

    /** A read through a logical database name sees every prior write, whichever node committed it. */
    case READ_AFTER_WRITE = 'read_after_write';

    /**
     * Says what the project takes on by declaring this promise, in the words a refusal prints.
     *
     * @return string The obligation, one sentence without a trailing period
     */
    public function obligation(): string
    {
        return match ($this) {
            self::ONE_LOGICAL_DATABASE => 'every node and every process resolves each logical database name to the same logical database',
            self::READ_AFTER_WRITE => 'a read through a logical database name sees every write committed to it before the read, '
                . 'whichever node committed it',
        };
    }
}
