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
     * The same path takes a node back into the freeze from the verification window for a new
     * operation (HIL-1057).
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
     * Takes the browsers the window lets in - the operator's and every circle member's - into the
     * verification window, once this node's roster is back.
     *
     * The second half of {@see enterVerifying()}, reached out of
     * {@see ProtectedModeSwitch::onRosterResumed()}: the tabs of those sessions leave the stub and
     * their pages are answered again. Those pages are answered by the agents the lift brings back, which
     * is why this half waits for the lift. The broadcast to everyone else does not wait: it needs no
     * agent, and held back it would overtake frames sent after it. Nothing is carried between the
     * two halves - the row is read again.
     */
    public function finishVerifying(): void;

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
     * Here the phase is written BEFORE the stop is asked for, the other way round from the window:
     * the roster stops over several master passes, and only the phase closes the agent-start gate
     * those passes interleave with.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function reenterActive(): void;

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
     * Tells this node's connections that the mode has lifted, once its roster is back.
     *
     * The second half of {@see enterInactive()}, reached out of
     * {@see ProtectedModeSwitch::onRosterResumed()}. The frame means "reload", and a browser that
     * reloads before the agents behind its page were asked for would be answered by nobody.
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
