<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Cluster\Peer\DTO\PeerClientFanoutDTO;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;

/**
 * RemoteFanoutDestination - Carries a browser fan-out to the other cluster nodes.
 *
 * The odd one out among destinations, and deliberately so: every other one names a target,
 * while this one names a job. A fan-out ({@see SignalRouter} resolving ws_all, ws_group or
 * ws_all_connected) has no addressable target outside this node — the subscription registry
 * is node-local, so the node holding a browser is the only one that can say whether it is
 * subscribed. This marker is therefore added ALONGSIDE the local destinations, never in
 * place of them: the local half is delivered here, and {@see DaemonManager} turns the marker
 * into one {@see PeerClientFanoutDTO} broadcast, which every other node expands against its
 * own registry.
 *
 * Fieldless because the job is fully described by the signal it travels with: the fan-out
 * kind, the target group and the excluded accept key all live in its WebSocketSignalData, so
 * a field here would be a second copy of what the receiving node reads anyway.
 *
 * It is contributed on a clustered node whether or not any peer is currently linked, for the
 * reason a fan-out exists at all — nobody asks who is subscribed before broadcasting. A node
 * that receives the frame expands it locally and passes it to no one: one hop, exactly like
 * every other peer frame.
 */
final class RemoteFanoutDestination implements Destination
{
}
