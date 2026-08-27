<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Peer\PeerServer;

/**
 * Outbound peer port the daemon declares and arbitrates RT ownership through.
 *
 * The mirror of {@see RtClaimSink}, and the claim-side counterpart of {@see RtSyncMesh}: that
 * one carries a fact this node wrote, this one carries the right it holds to write it. It hides
 * the {@see PeerServer} behind the four sends the guard needs, for the reason every mesh port
 * in this framework exists — the announcing side stays logic a test can drive with a fake
 * instead of a listener and a live link.
 *
 * A claim is ANNOUNCED rather than addressed at the leader, and that is not a convenience: only
 * a master runs consensus, so a data-plane node has no leadership seam and cannot name the
 * leader at all ({@see PendingLeadership} answers null for the life of the process). Addressed,
 * the report would be silently dropped on exactly the nodes the placed agents run on. The same
 * answer placement reaches for — a node hands its hosted set to every peer that links, and a
 * peer that does not lead ignores it.
 *
 * A verdict, by contrast, is told to the one node it is against, and the re-query goes to all
 * because a fresh leader has no map to address anything from.
 */
interface RtClaimMesh
{
    /**
     * Announces everything this node's agents own of the RT state to the whole mesh.
     *
     * Best-effort and unbuffered, like every other send on this channel: a node that is not
     * linked right now simply misses this report and is told again by the link that comes back
     * and by the re-query a fresh leader broadcasts. So a verdict is deferred rather than lost,
     * and the caller has nothing to retry.
     *
     * A node that leads is told by itself, and the implementation folds the claims in without a
     * frame: the caller says what it owns and never asks who judges it.
     *
     * @param list<PeerRtClaimEntry> $claims What each agent of this node owns
     */
    public function announceRtClaims(array $claims): void;

    /**
     * Tells one node everything this node's agents own, because that link has just appeared.
     *
     * The narrow form of {@see announceRtClaims()}, for the cue that names a single peer: what
     * this node owns has not moved, but until this link existed there was nobody there to hear
     * it. Announcing to the whole mesh on every link would re-tell four nodes what they already
     * hold.
     *
     * @param string $nodeId Node to tell
     * @param list<PeerRtClaimEntry> $claims What each agent of this node owns
     */
    public function sendRtClaimsToNode(string $nodeId, array $claims): void;

    /**
     * Asks every handshaked peer what its agents own, as a fresh leader rebuilding its map.
     */
    public function broadcastRtClaimsQuery(): void;

    /**
     * Tells one node that a claim its agent made is refused in favour of another node's.
     *
     * @param string $nodeId Node whose claim lost
     * @param PeerRtClaimRefusedDTO $refusal What was claimed, by which agent, and who holds it
     */
    public function sendRtClaimRefused(string $nodeId, PeerRtClaimRefusedDTO $refusal): void;
}
