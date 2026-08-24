<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Outbound peer port the daemon announces its own browser connections through.
 *
 * The mirror of {@see ClientSignalSink}: that one carries another node's fact in, this one
 * carries a local fact out. It hides the {@see PeerServer} behind the sends the connection
 * index needs, for the reason every mesh port in this framework exists — the announcing side
 * stays logic a test can drive with a fake instead of a listener and a live link.
 *
 * The two connection sends are about the same set and differ only in who is behind on it: a
 * node that has just linked is behind on everything and is handed the whole set, while the
 * nodes already linked are current and are told only what changed. Same split as
 * {@see RtSyncMesh}.
 *
 * The two signal sends split on a different line — whether the target is known. An addressed
 * one goes to the single node the index places the browser on; a fan-out goes to everyone,
 * because no index can answer who is subscribed.
 */
interface ClientMesh
{
    /**
     * Delivers one signal to a browser attached to another node.
     *
     * Addressed, because a browser hangs on exactly one node and this index knows which. The
     * answer says whether a live link carried the frame — false is not an error but the
     * caller's cue to drop and log, the same contract {@see PeerServer::sendSignalToNode()}
     * has, and the same outcome the local path already produces for a socket that is gone.
     *
     * @param string $nodeId Id of the node holding the connection
     * @param string $acceptKey Accept key of the connection to deliver to
     * @param SignalDTO $signal Signal to deliver on that node
     * @return bool True when a live link carried the frame, false when the node is unlinked
     */
    public function sendSignalToClientNode(string $nodeId, string $acceptKey, SignalDTO $signal): bool;

    /**
     * Asks every other node to fan one signal out to the browsers it holds.
     *
     * Broadcast and unaddressed, because a fan-out has no address to be sent to: which
     * browsers it reaches is answered by the node-local subscription registry, so the sending
     * node can only name the signal and let each receiver expand it against its own. Nothing
     * else describes the job — the fan-out kind, the target group and the excluded accept key
     * travel inside the signal.
     *
     * Best-effort in the same sense as {@see broadcastConnectionsDelta()}: a node that is not
     * linked right now misses the fan-out, and unlike a connection set there is nothing to
     * hand it later — a signal nobody was there to receive is gone, exactly as it is for a
     * browser that was not connected when it was sent.
     *
     * @param SignalDTO $signal Signal every node expands against its own subscription registry
     */
    public function broadcastClientFanout(SignalDTO $signal): void;

    /**
     * Hands one node the whole set of browser connections this node holds.
     *
     * Addressed, and sent off the handshake rather than off membership: a node is a member as
     * soon as a peer mentions it, which is well before this node has a link to reach it with.
     *
     * @param string $nodeId Node this one can now reach
     * @param list<string> $acceptKeys Every accept key this node holds right now
     */
    public function sendConnectionsSnapshotToNode(string $nodeId, array $acceptKeys): void;

    /**
     * Announces which browser connections this node has gained and lost to every other node.
     *
     * Broadcast rather than addressed: any node may be the one whose agent answers one of
     * these browsers, so there is nobody to leave out. Delivery is best-effort — a node that
     * is not linked right now simply misses the delta and is handed the whole set when it
     * links.
     *
     * @param list<string> $opened Accept keys this node has gained since its last announcement
     * @param list<string> $closed Accept keys this node has lost since its last announcement
     */
    public function broadcastConnectionsDelta(array $opened, array $closed): void;
}
