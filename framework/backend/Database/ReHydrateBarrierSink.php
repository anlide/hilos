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
     * The number the participant echoed back travels with the answer, because the label alone
     * cannot tell this round's answer from the previous one's (HIL-694).
     *
     * @param int $round Number the answering participant echoed back, 0 when the frame carried none
     * @param string $participant Participant label, from {@see ReHydrateRound}'s factories
     * @param bool $ok Whether that participant re-read its collections successfully
     * @param ?string $error Failure text when it did not
     */
    public function ackReHydrateParticipant(int $round, string $participant, bool $ok, ?string $error): void;

    /**
     * Takes a participant that disappeared off the open re-hydrate barrier.
     *
     * @param string $participant Participant label, from {@see ReHydrateRound}'s factories
     */
    public function dropReHydrateParticipant(string $participant): void;
}
