<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Local-node effects port the {@see ClusterProtectedMode} orchestration drives this node through.
 *
 * The coordinator decides the freeze transitions; this port applies them here — writing the
 * {@see ProtectedModeRuntime} row (the daemon truth source registered in
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
     *                                    freezes itself, recorded for the verification window rather
     *                                    than let through this phase; null on a follower, which has
     *                                    no initiator connection to name
     * @param ?string $initiatorSessionTokenHash Hash of the session token behind that connection, on the
     *                                           same terms; null on a follower and whenever the freeze
     *                                           was asked for by something without a browser
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
        ?string $initiatorSessionTokenHash,
    ): void;

    /**
     * Marks the cluster-wide freeze active locally once every follower has quiesced.
     *
     * Leader-only: the follower rows stay at activating (they are already locked out) since no
     * activated frame exists; active is the leader's marker that the initiator may run.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterActive(): void;

    /**
     * Marks the freeze deactivating locally before the leader broadcasts the lift.
     *
     * Leader-only.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterDeactivating(): void;

    /**
     * Opens the verification window on this node: writes phase verifying and brings the agents back.
     *
     * The agents come back here rather than at the lift, because a verifier has nothing to look at
     * while the page agents are stopped. The phase is written FIRST: the agent-start gate refuses
     * every start while the phase is not inactive, so a resume ordered before the phase moved would
     * hand the verifier an empty system.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterVerifying(): void;

    /**
     * Tells this node's locked-out connections that the verification window now has a code to take.
     *
     * Called at zero-to-one and nowhere else: the window opens saying nothing has been minted, and
     * the first pass turns that sentence into the field with nothing clicked. Later mints announce
     * nothing - the bit already says what they would say, and a frame per mint would broadcast to
     * every frozen browser without changing anything on any of them. Nothing is written here: the
     * row already holds the hash by the time this runs.
     */
    public function announcePassIssued(): void;

    /**
     * Closes this node back from the verification window: writes phase active and stops the agents again.
     *
     * The mirror of {@see enterVerifying()}, and not the same thing as {@see enterActive()}: that
     * one only marks the freeze established, while this one has agents to stop and passes to void.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function reenterActive(): void;

    /**
     * Releases this node: writes phase inactive locally and resumes the agents that were stopped.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
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
