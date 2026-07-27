<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;

/**
 * Local-node effects port the {@see ClusterProtectedMode} orchestration drives this node through.
 *
 * The coordinator decides the freeze transitions; this port applies them here — writing the
 * {@see \Hilos\Runtime\State\Item\ProtectedModeRuntime} row (the daemon truth source registered in
 * HIL-267 slice 2a) so this node's workers see the phase, and stopping or resuming the node's own
 * agents. Both the leader and every follower own one: the leader freezes itself the same way it
 * orders followers, and both roles release the same way. Keeping the effects behind this seam lets
 * the state machine be unit-tested with a fake and lets the mass agent-stop land in a later slice.
 */
interface ProtectedModeExecutor
{
    /**
     * Freezes this node: writes phase activating locally and stops the node's own agents, leaving
     * the initiator agent named in the descriptor running.
     *
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity the freeze protects
     * @param ?string $initiatorAcceptKey Accept key of the initiator connection when the leader
     *                                    freezes itself; null on a follower, which locks out every
     *                                    connection and has no initiator to let through
     */
    public function enterActivating(ProtectedModeQuiesceData $freeze, ?string $initiatorAcceptKey): void;

    /**
     * Marks the cluster-wide freeze active locally once every follower has quiesced.
     *
     * Leader-only: the follower rows stay at activating (they are already locked out) since no
     * activated frame exists; active is the leader's marker that the initiator may run.
     */
    public function enterActive(): void;

    /**
     * Marks the freeze deactivating locally before the leader broadcasts the lift.
     *
     * Leader-only.
     */
    public function enterDeactivating(): void;

    /**
     * Releases this node: writes phase inactive locally and resumes the agents that were stopped.
     */
    public function enterInactive(): void;

    /**
     * Relays to the local initiator agent that the cluster has quiesced and its operation may run.
     *
     * Runs on the initiator's node when the leader's ready frame arrives; the worker bridge that
     * carries it to the agent lands in a later slice.
     */
    public function notifyInitiatorReady(): void;
}
