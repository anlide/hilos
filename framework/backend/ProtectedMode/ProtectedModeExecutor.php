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
     * Freezes this node: writes phase activating locally and asks for the node's own agents to be
     * stopped, leaving the initiator agent named in the descriptor running. The roster stops over
     * several master passes; the switch hears the end of it through
     * {@see ProtectedModeSwitch::onRosterStopped()}, and only from there says the node is frozen.
     * The same path takes a node back into the freeze from the verification window, for a new
     * operation (HIL-1057) and for the close back from it (HIL-1128).
     *
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity the freeze protects
     * @param ?string $initiatorAcceptKey Accept key of the initiator connection when the leader
     *                                    freezes itself, recorded for the verification window rather
     *                                    than let through this phase; null on a follower, which has
     *                                    no initiator connection to name
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void;

    /**
     * Writes a direct verification window and persists it without touching the agent roster.
     *
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity
     * @param ?string $initiatorAcceptKey Initiating connection, or null for CLI entry
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterVerificationWindow(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void;

    /**
     * Marks the freeze active locally once every agent it stops has stopped.
     *
     * A single node and the leader write it at the end of the round, a follower on its leader's
     * settled frame (HIL-1128); it pushes nothing to browsers, which are already locked out.
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
     * Opens the verification window on this node: writes phase verifying and asks for the agents back.
     *
     * The agents come back here rather than at the lift, because a verifier has nothing to look at
     * while the page agents are stopped. The phase is written FIRST: the agent-start gate refuses
     * every start while the phase is not inactive, so a resume ordered before the phase moved would
     * hand the verifier an empty system.
     *
     * The locked-out browsers are told here, with the phase: the stub stays and may offer a code
     * field. What the operator is told comes once the roster is back, from {@see finishVerifying()},
     * because it takes their tabs onto pages the returning agents answer (HIL-1012).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterVerifying(): void;

    /**
     * Takes the initiating browser and photographed circle into the verification window.
     *
     * The second half of {@see enterVerifying()}, reached out of
     * {@see ProtectedModeSwitch::onRosterResumed()} on an ordinary freeze, or immediately after
     * the direct entry's circle photograph: personal frames and page reassessment follow the
     * window frame. The ordinary path waits for resumed agents; the direct path never stops them.
     */
    public function finishVerifying(): void;

    /**
     * Tells this node's locked-out connections that the verification window now has a code to take.
     *
     * Called at zero-to-one and nowhere else: the window opens saying nothing has been minted, and
     * the first pass turns that sentence into the field with nothing clicked. Later mints announce
     * nothing - the bit already says what they would say, and a frame per mint would reach every
     * frozen browser without changing anything on any of them. Nothing is written here: the
     * row already holds the hash by the time this runs.
     */
    public function announcePassIssued(): void;

    /**
     * Records a code admission on this master's row and tells its tabs on the first crossing.
     *
     * @param string $sessionTokenHash Hash of the verifier session
     */
    public function admitVerifier(string $sessionTokenHash): void;

    /**
     * Releases this node: writes phase inactive locally and asks for the agents that were stopped.
     *
     * Ends at the request, as {@see enterVerifying()} does: the lift frame goes out from
     * {@see finishLift()} once the roster is back (HIL-1012).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterInactive(): void;

    /**
     * Tells this node's connections that the mode has lifted.
     *
     * The second half of {@see enterInactive()}, reached out of
     * {@see ProtectedModeSwitch::onRosterResumed()} for an ordinary freeze; a direct window calls
     * it immediately, with no stopped roster. The frame means "reload".
     */
    public function finishLift(): void;

    /**
     * Relays to the local initiator agent that the cluster has quiesced and its operation may run.
     *
     * Runs on the initiator's node when the leader's ready frame arrives; the worker bridge that
     * carries it to the agent lands in a later slice.
     */
    public function notifyInitiatorReady(): void;
}
