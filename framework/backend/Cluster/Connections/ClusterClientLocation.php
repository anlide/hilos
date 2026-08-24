<?php

declare(strict_types=1);

namespace Hilos\Cluster\Connections;

use Hilos\Cluster\ClientLocation;
use Hilos\Cluster\Peer\DTO\PeerDTO;

/**
 * The cluster's index of which node each browser connection is attached to.
 *
 * One of these lives on every clustered master. It holds two things: what the peers have
 * announced about their own sockets ({@see applySnapshot()}, {@see applyDelta()}), and what
 * this node last announced about its own ({@see diffLocal()}). The first half answers
 * {@see nodeFor()}, which is how a signal resolved on one node finds the node the browser
 * hangs on; the second is what keeps every other node's first half true.
 *
 * The local half is a DIFF rather than a pair of hooks, and that is the load-bearing decision.
 * A connection closes in the master from three different places — the orderly close, the
 * discard on a read error, and the detach at shutdown — so a hook would have to be hung on
 * each, and the one that got missed would leave a ghost in the index of every other node:
 * signals addressed to a socket that is gone, forever. A diff cannot miss a path, because it
 * never looks at the paths — only at what the socket set is now versus what it was when this
 * node last spoke. It batches for free too, which is what a restart's reconnect storm needs.
 *
 * Nothing here is durable and nothing here is authoritative: an accept key belongs to the node
 * that minted it, and every other node holds a copy that is only as fresh as the last frame it
 * received. That is enough, because a stale entry resolves to a node that will simply not find
 * the socket, which is the same outcome as the local path already has for a client that left.
 */
final class ClusterClientLocation implements ClientLocation
{
    /** @var array<string, string> Accept key => id of the node that announced it */
    private array $nodeByAcceptKey = [];

    /** @var array<string, array<string, true>> Node id => set of accept keys it announced */
    private array $acceptKeysByNode = [];

    /** @var array<string, true> Accept keys this node announced to the mesh last time it spoke */
    private array $announcedLocal = [];

    /** @var array<string, true> Accept keys attached through the test door */
    private array $attachedLocal = [];

    /** @var int Cross-node deliveries this node has accepted for its browsers since start */
    private int $deliveries = 0;

    /** @var ?string Browser the last addressed cross-node delivery was for, or null when there has been none */
    private ?string $lastAcceptKey = null;

    /**
     * Returns the id of the node holding a browser connection when it is another node.
     *
     * @param string $acceptKey Browser connection to look up
     * @return ?string Holding node id when remote, or null for local / unknown
     */
    public function nodeFor(string $acceptKey): ?string
    {
        return $this->nodeByAcceptKey[$acceptKey] ?? null;
    }

    /**
     * Replaces everything this index holds for one node with the set that node just announced.
     *
     * Replacement rather than merge, exactly as an RT snapshot is: the announcing node is the
     * whole truth about its own sockets, so a key its snapshot does not carry is a connection
     * that no longer exists.
     *
     * @param string $nodeId Node the connections belong to
     * @param list<string> $acceptKeys Every accept key that node holds right now
     */
    public function applySnapshot(string $nodeId, array $acceptKeys): void
    {
        $this->forgetNode($nodeId);

        foreach ($acceptKeys as $acceptKey) {
            $this->bind($nodeId, $acceptKey);
        }
    }

    /**
     * Applies one node's report of the connections it has gained and lost.
     *
     * Closings are applied after openings so that a key appearing in both lists ends up closed.
     * The wire does not produce such a frame — a diff puts a key in one list or neither — but
     * the order decides what a malformed one does, and forgetting a connection is the harmless
     * way to be wrong: the index then says "unknown", and an unknown key is served by the local
     * path, whereas a phantom key sends signals to a node that has nowhere to put them.
     *
     * @param string $nodeId Node the connections belong to
     * @param list<string> $opened Accept keys that node has gained
     * @param list<string> $closed Accept keys that node has lost
     */
    public function applyDelta(string $nodeId, array $opened, array $closed): void
    {
        foreach ($opened as $acceptKey) {
            $this->bind($nodeId, $acceptKey);
        }

        foreach ($closed as $acceptKey) {
            $this->unbind($nodeId, $acceptKey);
        }
    }

    /**
     * Drops everything this index holds for a node that has left the cluster.
     *
     * Driven by membership going down rather than by a link breaking: a broken link is healed
     * by re-dialing, and dropping the node's connections on it would blind this node to every
     * browser attached there for as long as the reconnect takes.
     *
     * @param string $nodeId Node that left
     */
    public function forgetNode(string $nodeId): void
    {
        foreach (array_keys($this->acceptKeysByNode[$nodeId] ?? []) as $acceptKey) {
            unset($this->nodeByAcceptKey[$acceptKey]);
        }

        unset($this->acceptKeysByNode[$nodeId]);
    }

    /**
     * Counts the connections this index holds per node.
     *
     * For the cluster inspection command: a test harness asserts on the shape of the index
     * without being handed the keys themselves, which are meaningless outside their node.
     *
     * A node with nothing attached to it is absent rather than zero: the map holds the nodes
     * that hold connections, and one that announced an empty set is in no way different from
     * one that has never spoken.
     *
     * @return array<string, int> Node id => number of accept keys held for it
     */
    public function countsByNode(): array
    {
        return array_map(count(...), $this->acceptKeysByNode);
    }

    /**
     * Records that a signal addressed to one browser here arrived from another node.
     *
     * The harness's window onto the receiving end, and the only one there is on
     * `demo/cluster`: that demo runs headless, so the write itself lands nowhere and a
     * scenario asserting on delivery has nothing else to read. Counted the moment the frame is
     * accepted, before the socket is looked for, which is exactly what "this node was asked"
     * means — whether a socket was there to write to is the local path's business.
     *
     * @param string $acceptKey Browser the signal was addressed to
     */
    public function noteAddressedDelivery(string $acceptKey): void
    {
        $this->deliveries++;
        $this->lastAcceptKey = $acceptKey;
    }

    /**
     * Records that a fan-out from another node arrived for the browsers here.
     *
     * The same tally, and no accept key: a fan-out names no browser, and the ones it reaches
     * are decided here rather than announced. It leaves {@see lastAcceptKey()} alone rather
     * than blanking it, so the two facts stay independent — the count says a frame arrived, the
     * key says who the last addressed one was for.
     */
    public function noteFanoutDelivery(): void
    {
        $this->deliveries++;
    }

    /**
     * Counts the cross-node deliveries this node has accepted for its browsers.
     *
     * @return int Addressed signals and fan-outs taken in since start
     */
    public function deliveries(): int
    {
        return $this->deliveries;
    }

    /**
     * Names the browser the last addressed cross-node signal was for.
     *
     * @return ?string Accept key of the last addressed delivery, or null when there has been none
     */
    public function lastAcceptKey(): ?string
    {
        return $this->lastAcceptKey;
    }

    /**
     * Adds a browser connection to this node's own set without a socket behind it.
     *
     * The test door, and the only way `demo/cluster` can be driven at all: that demo runs
     * headless, with no WebSocket server and so no sockets to diff, yet the whole point of the
     * index is what happens between nodes. An attached key is announced, resolved and
     * delivered to through the same path a real one is — everything downstream of the socket
     * itself is the production path, which is what makes the scenario worth running.
     *
     * @param string $acceptKey Browser connection to pretend this node holds
     */
    public function attachLocal(string $acceptKey): void
    {
        $this->attachedLocal[$acceptKey] = true;
    }

    /**
     * Takes a browser connection attached through the test door back off this node's set.
     *
     * @param string $acceptKey Browser connection to stop pretending about
     */
    public function detachLocal(string $acceptKey): void
    {
        unset($this->attachedLocal[$acceptKey]);
    }

    /**
     * Returns the accept keys this node has announced to the mesh.
     *
     * What a node that has just linked is handed, and therefore what {@see diffLocal()} has
     * settled on — not what the sockets say this instant. The two agree at every point a
     * hand-over can happen, and taking the announced set keeps the snapshot and the deltas
     * telling one story even if they ever did not.
     *
     * @return list<string> Accept keys this node holds, as the mesh has been told
     */
    public function announcedLocalKeys(): array
    {
        return self::asKeyList($this->announcedLocal);
    }

    /**
     * Diffs this node's current browser connections against what it last announced.
     *
     * The observed keys are the live sockets; the test door's attachments are added to them,
     * so a pretend connection is announced and forgotten on exactly the terms a real one is.
     * The result is what the mesh has not been told yet, and the announced set moves to the
     * current one — so a caller that drops the returned delta drops the announcement with it.
     * That is why this is the daemon's line and not something a hook may call: telling the
     * mesh is not optional once the diff has been taken.
     *
     * @param list<string> $observed Accept keys this node's WebSocket server holds right now
     * @return array{opened: list<string>, closed: list<string>} What changed since the last announcement
     */
    public function diffLocal(array $observed): array
    {
        $current = $this->attachedLocal;
        foreach ($observed as $acceptKey) {
            $current[$acceptKey] = true;
        }

        $opened = self::asKeyList(array_diff_key($current, $this->announcedLocal));
        $closed = self::asKeyList(array_diff_key($this->announcedLocal, $current));

        $this->announcedLocal = $current;

        return ['opened' => $opened, 'closed' => $closed];
    }

    /**
     * Reads a set of accept keys back out as the strings they were put in as.
     *
     * PHP has no string array key that reads as a decimal integer: an accept key of "12345"
     * becomes int 12345 the moment it is used as one, and {@see array_keys()} hands it back an
     * int. Every key here leaves for the wire — the snapshot and the delta are lists of accept
     * keys — where {@see PeerDTO::readAcceptKeys()} refuses anything that is not a string, and
     * rightly so: a frame it cannot read is a connection nothing could ever address. So the
     * restoring happens here, where the set is owned, rather than by loosening the reader.
     *
     * Real sockets never hit this, since an accept key is base64url of random bytes; the test
     * door ({@see attachLocal()}) takes whatever a harness names, and that is the whole reason
     * a set of accept keys cannot be handed out as its raw keys.
     *
     * @param array<string|int, true> $keySet Set of accept keys, as this index holds it
     * @return list<string> The same keys, every one of them a string
     */
    private static function asKeyList(array $keySet): array
    {
        return array_map(strval(...), array_keys($keySet));
    }

    /**
     * Records that a node holds a browser connection, taking it off whoever held it before.
     *
     * The reassignment is what keeps the two maps one fact rather than two: an accept key is
     * minted by the node that accepted the socket and is held by that node alone, so a second
     * claim on it can only mean this index is behind on the first one.
     *
     * @param string $nodeId Node announcing the connection
     * @param string $acceptKey Browser connection being announced
     */
    private function bind(string $nodeId, string $acceptKey): void
    {
        $previousNodeId = $this->nodeByAcceptKey[$acceptKey] ?? null;
        if ($previousNodeId !== null && $previousNodeId !== $nodeId) {
            $this->drop($previousNodeId, $acceptKey);
        }

        $this->nodeByAcceptKey[$acceptKey] = $nodeId;
        $this->acceptKeysByNode[$nodeId][$acceptKey] = true;
    }

    /**
     * Forgets a browser connection a node reports it no longer holds.
     *
     * A close is honoured only from the node the index credits with the connection: a late
     * frame from its previous holder would otherwise erase the entry the new one just made.
     *
     * @param string $nodeId Node announcing the close
     * @param string $acceptKey Browser connection being closed
     */
    private function unbind(string $nodeId, string $acceptKey): void
    {
        if (($this->nodeByAcceptKey[$acceptKey] ?? null) !== $nodeId) {
            return;
        }

        unset($this->nodeByAcceptKey[$acceptKey]);
        $this->drop($nodeId, $acceptKey);
    }

    /**
     * Takes one accept key out of a node's set, and the node out of the map once its set is
     * empty.
     *
     * The emptying is what keeps {@see countsByNode()} a list of nodes that hold connections
     * rather than a list of every node that has ever announced one.
     *
     * @param string $nodeId Node losing the connection
     * @param string $acceptKey Connection being taken off it
     */
    private function drop(string $nodeId, string $acceptKey): void
    {
        unset($this->acceptKeysByNode[$nodeId][$acceptKey]);
        if (($this->acceptKeysByNode[$nodeId] ?? null) === []) {
            unset($this->acceptKeysByNode[$nodeId]);
        }
    }
}
