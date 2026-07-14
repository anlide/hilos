<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;

/**
 * Connection policy: decides which known peers the local node dials a direct link to.
 *
 * This is the one seam that separates membership *knowledge* (the master registry
 * and the gossip that fills it) from connection *policy* (which of the known peers
 * we actually hold a direct link to). The transport learns every peer through
 * gossip regardless; the policy alone chooses the dial targets, so a later
 * partial-mesh topology is a policy swap ({@see FullMeshConnectionPolicy} today)
 * plus multi-hop routing (HIL-180), with the registry and gossip untouched.
 *
 * Addressing stays logical: a policy reasons about node identities, never about
 * "there is always a direct link to node X". The transport owns the mechanics the
 * policy does not decide — never dialing self, de-duplicating against a link that
 * already exists, and skipping a peer with no advertised address.
 */
interface ConnectionPolicy
{
    /**
     * Decides whether the local node should hold a direct link to a known peer.
     *
     * Consulted once per known peer; the transport still applies its own dial
     * guards (self, an existing link, a missing advertised address) on top of a
     * true result.
     *
     * @param NodeIdentity $local Local node identity
     * @param ClusterNode $candidate Known peer weighed as a dial target
     * @return bool True when a direct link to the candidate is wanted
     */
    public function shouldDial(NodeIdentity $local, ClusterNode $candidate): bool;
}
