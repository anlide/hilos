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
 * Both are correct only if every node reads the same database and a read returns what was written.
 * The framework sees one database address and leaves the topology behind it to the project, so the
 * project states that the topology keeps both - in {@see Hilos::DATABASE_GUARANTEES} - and a node
 * whose project did not state it refuses to start instead of reading wrong rows quietly.
 *
 * Only the first promise is checked: in a cluster every node names the marker of the database it
 * reads on the peer handshake, and a node reading another database is admitted by nobody
 * ({@see PeerMarkers}). The second one is only declared. A lagging replica cannot be caught by a
 * probe - one that caught up by the time of the probe passes it - and a check of the server's own
 * setting would refuse a correct configuration: Galera behind a proxy that sends everything to one
 * server keeps the promise with the setting off.
 */
enum DatabaseGuarantee: string
{
    /** Every node and every process of an installation reads and writes one logical database. */
    case ONE_LOGICAL_DATABASE = 'one_logical_database';

    /** A read sees every write committed before it, whichever node committed it. */
    case READ_AFTER_WRITE = 'read_after_write';

    /**
     * Says what the project takes on by declaring this promise, in the words a refusal prints.
     *
     * @return string The obligation, one sentence without a trailing period
     */
    public function obligation(): string
    {
        return match ($this) {
            self::ONE_LOGICAL_DATABASE => 'every node and every process of an installation reads and writes one logical database',
            self::READ_AFTER_WRITE => 'a read sees every write committed before it, whichever node committed it',
        };
    }
}
