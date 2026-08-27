<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsQueryDTO;

/**
 * Local port between the peer transport and the daemon's view of who owns which RT state.
 *
 * The receiving half of the two-owner guard, and the mirror of {@see RtClaimMesh}. It exists
 * for the reason {@see RtSyncSink} does — the transport must not reach into the daemon, and a
 * test supplies a fake so the transport runs without one — but it answers a different question:
 * that seam carries the CONTENT of an RT write, this one carries the RIGHT to make it.
 *
 * The three cues are the three claim frames arriving: a node's report to the leader
 * ({@see PeerRtClaimsDTO}), a fresh leader's re-query ({@see PeerRtClaimsQueryDTO}), and the
 * leader's verdict against one claim ({@see PeerRtClaimRefusedDTO}).
 */
interface RtClaimSink
{
    /**
     * Leader side: folds one node's whole ownership report into the cluster-wide map and judges it.
     *
     * Ignored on a node that does not lead: the map is the leader's, and a second judge would
     * refuse claims nobody asked it about.
     *
     * @param string $nodeId Node whose agents hold the claims
     * @param list<PeerRtClaimEntry> $claims What each agent of that node owns
     */
    public function applyRemoteRtClaims(string $nodeId, array $claims): void;

    /**
     * Node side: reports everything this node's agents own, to one node or to the whole mesh.
     *
     * Named node: a link has just appeared, or a fresh leader has asked over it — either way the
     * one peer that has yet to hear this node is the one to tell. Null: what this node owns has
     * moved, and which peer leads is a question a data-plane node cannot answer, so it tells all
     * of them and the one that judges takes it.
     *
     * @param ?string $nodeId Node to report to, or null to announce to the whole mesh
     */
    public function reportRtClaims(?string $nodeId): void;

    /**
     * Node side: acts on the leader's refusal of one claim made here.
     *
     * @param PeerRtClaimRefusedDTO $refusal What was claimed, by which agent, and who holds it
     */
    public function applyRtClaimRefusal(PeerRtClaimRefusedDTO $refusal): void;
}
