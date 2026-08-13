<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;

/**
 * ReHydrateBarrierSink - where answers to a database re-hydrate announcement are credited.
 *
 * The barrier state lives on the daemon side ({@see AgentManagerDaemon}), but answers arrive on
 * two different transports: workers reply over the worker link, cluster nodes over the peer mesh.
 * This is the narrow seam the peer side is given, so {@see PeerServer} can credit a node's answer
 * without knowing anything about agents or workers - the same shape as the protected-mode relays
 * the cluster context already registers.
 */
interface ReHydrateBarrierSink
{
    /**
     * Records one participant's answer to the open re-hydrate barrier.
     *
     * @param string $participant Participant label, from {@see ReHydrateRound}'s factories
     * @param bool $ok Whether that participant re-read its collections successfully
     * @param ?string $error Failure text when it did not
     */
    public function ackReHydrateParticipant(string $participant, bool $ok, ?string $error): void;

    /**
     * Takes a participant that disappeared off the open re-hydrate barrier.
     *
     * @param string $participant Participant label, from {@see ReHydrateRound}'s factories
     */
    public function dropReHydrateParticipant(string $participant): void;
}
