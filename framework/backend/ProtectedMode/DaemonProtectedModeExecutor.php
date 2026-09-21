<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Utils\Logger;
use JsonException;

/**
 * Daemon-master implementation of {@see ProtectedModeExecutor}: applies the freeze transitions the
 * {@see ClusterProtectedMode} orchestration decides to this node's local runtime row.
 *
 * It writes the {@see ProtectedModeRuntime} singleton through Hilos::$rt — the daemon truth source
 * registered in HIL-267 slice 2a — so every worker on this node sees the current phase and the
 * master's welcome path and the browser page guards can lock connections out. Both the leader
 * (freezing itself) and every follower own one, and both release the same way. A process holding no
 * runtime state writes nothing and says so in the log; entering the mode is refused before it gets
 * this far ({@see ClusterProtectedMode}, {@see StandaloneProtectedMode}), so that branch is defense
 * in depth rather than a state a freeze can normally reach.
 *
 * {@see notifyInitiatorReady()} relays the leader's ready to the initiator agent by addressing the
 * worker hosting it through {@see ProtectedModeInitiatorRelay}, reading the initiator identity back from
 * the runtime row this node wrote on entry. On entry it stops this node's own agents through
 * {@see ProtectedModeAgentFreezer}, leaving the initiator agent running; on exit ({@see enterInactive()})
 * the same freezer brings back exactly the agents it stopped. The freezer does both one agent per
 * master pass, so the two transitions that bring agents back are split in two: the phase write, the
 * request and the stub broadcast here, and the frames that send browsers onto pages in
 * {@see finishVerifying()} and {@see finishLift()}, which the switch calls once the roster is back
 * (HIL-1012).
 *
 * The phases a browser can see - entering, opening the verification window, closing back from it
 * and lifting - are also pushed to this node's open connections through
 * {@see ProtectedModeClientNotifier} (HIL-268), so a page that was already loaded when the freeze
 * landed learns about it instead of waiting for a refused subscription. `active` and
 * `deactivating` push nothing: the surface is already up and must stay up. The two verification
 * frames say `active: true` as well, and differ only in whether the surface may offer a code
 * field - the stub has to stay up for everyone who holds no pass. {@see announcePassIssued()} is
 * the one push that moves no phase: it re-sends the verification frame with the second bit raised
 * when the first pass lands, so a verifier already looking at the stub gets the field without
 * touching anything.
 *
 * Every phase this class writes is also left on disk through {@see ProtectedModeFreezeStore}, and
 * the lift removes it: the row is memory only, so a daemon restarted under a freeze would otherwise
 * come back open over a database its restore never finished (HIL-482).
 */
final class DaemonProtectedModeExecutor implements ProtectedModeExecutor
{
    /**
     * @param ProtectedModeFreezeStore $store Where the freeze is left for a daemon that restarts under it
     */
    public function __construct(
        private readonly ProtectedModeFreezeStore $store = new ProtectedModeFreezeStore(),
    ) {
    }

    /**
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity the freeze protects
     * @param ?string $initiatorAcceptKey Accept key recorded when the leader freezes itself, admitted once the
     *                                    verification window opens and not before; null on a follower
     * @param ?string $initiatorSessionTokenHash Hash of the initiator browser's session token, recorded and
     *                                           admitted on the same terms; null on a follower and when nobody
     *                                           with a browser asked
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
        ?string $initiatorSessionTokenHash,
    ): void {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $view->actions->enterActivating($freeze, $initiatorAcceptKey, $initiatorSessionTokenHash);
        $this->persistFreeze($view);

        // Stop this node's own agents so no application work runs against the destructive
        // operation, leaving the initiator agent running to carry it out (HIL-267 slice 7a).
        Hilos::$cluster?->protectedModeAgentFreezer()?->stopAgentsForProtectedMode(
            $freeze->initiatorAgentType,
            $freeze->initiatorAgentIndex === null ? null : (string)$freeze->initiatorAgentIndex,
        );

        // Tell the connections that were already open: the lockdown is total from this phase on,
        // so this is the earliest honest moment, and on a follower the phase never gets past it.
        // Nobody is left out, the initiator's own browser included - there is no application to
        // keep it in, the line above just stopped the agents behind every page. Its tabs go to the
        // same stub as everyone's, and the one the operation was asked from goes with them: what it
        // gets there is the restore panel, which is the only screen still being fed (HIL-718).
        $copy = ProtectedModeStubCopy::forOperation($freeze->operation);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(
                active: true,
                operation: $freeze->operation,
                title: $copy->title,
                message: $copy->message,
            ),
            null,
            null,
        );
    }

    /**
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterActive(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $view->actions->enterActive();
        $this->persistFreeze($view);
    }

    /**
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterVerifying(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        // The phase moves before the resume, and the order is load-bearing: WorkerServer refuses
        // every agent start while the phase is not inactive, and learns to allow them on verifying.
        // Resuming first would be refused start by start and hand the verifier an empty system.
        $view->actions->enterVerifying();
        $this->persistFreeze($view);

        Hilos::$cluster?->protectedModeAgentFreezer()?->resumeAgentsForProtectedMode();

        // The stub stays up for everyone without a pass, so the frame still says active: what it
        // adds is that this surface may now offer a code field. The window opens with nothing
        // minted, so the surface says to wait rather than showing a field that can take nothing.
        // The initiator is left out because it is owed the opposite verdict, and gets it once the
        // roster is back ({@see finishVerifying()}).
        //
        // Sent here, with the phase, and not with the roster: it is addressed to the locked out,
        // who need no agent to read it, and every frame after it assumes it has already landed.
        // Held until the roster was back, it overtook what came after the phase write - the
        // announcement of the first pass minted meanwhile, which it would reset to "nothing
        // minted", and a circle member already let in on a reload, whom it would put back on
        // the stub (HIL-1012).
        $copy = ProtectedModeStubCopy::forOperation($view->operation);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(
                active: true,
                operation: $view->operation,
                title: $copy->title,
                message: $copy->message,
                acceptsPass: true,
                passIssued: false,
            ),
            $view->initiatorAcceptKey,
            $view->initiatorSessionTokenHash,
        );
    }

    /**
     * The frames {@see enterVerifying()} owes everyone the window lets in once the roster is back:
     * the operator and every member of the circle photographed at the freeze.
     */
    public function finishVerifying(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        // This phase is where the window's own people come back in, and it has to be pushed:
        // entering the freeze tore no connection down, so every tab of the operator and of each
        // circle member is standing on the stub and would stand there for the whole window waiting
        // for an F5 nobody told them to press - the reload lets a circle member in on the 101, and
        // a reload changing what a tab is let into is the defect (HIL-912). The frame is the
        // opposite of the broadcast enterVerifying() sent - active: false, the mode does not hold
        // you - and it goes to the session so that all its tabs leave the stub at the same moment.
        // It is a second frame rather than one broadcast without the exclusion, because a personal
        // frame racing the general one would arrive in either order, and losing that race leaves
        // the operator on the stub in a system that is running again. The circle is not excluded
        // from that broadcast at all: it went out with the phase, before the roster, so this frame
        // lands after it.
        // acceptsPass stays true: it carries the row's own bit, and a client reading active: false
        // without it takes the frame for a lift and reloads itself back out of the window.
        // passIssued is read off the row as well: the window opens before anything is minted
        // (HIL-718), but this frame waits for the roster, and a pass can be minted meanwhile.
        // The stub copy stays null for the reason the frame exists - these tabs are leaving the
        // stub - and the banner sentence rides instead: it is what they render once they are out,
        // and it is the same $copy, read from the other side (HIL-736).
        //
        // The set is keyed by the hash, because the operator can be a member of their own circle
        // and is owed one frame, not two. Plain keys and not hash_equals: nothing is admitted by
        // this comparison - the row already decided that - and all that is at stake is a duplicate.
        $admitted = [];
        if ($view->initiatorSessionTokenHash !== null) {
            $admitted[$view->initiatorSessionTokenHash] = true;
        }
        foreach ($view->circleSessionTokenHashes as $circleSessionTokenHash) {
            $admitted[$circleSessionTokenHash] = true;
        }

        $copy = ProtectedModeStubCopy::forOperation($view->operation);
        foreach (array_keys($admitted) as $sessionTokenHash) {
            // One session failing must not cost the others their window, so each is tried alone.
            try {
                Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeSessionState(
                    new ProtectedModeStateSignalData(
                        active: false,
                        operation: $view->operation,
                        title: null,
                        message: null,
                        acceptsPass: true,
                        passIssued: $view->passHashes !== [],
                        bannerMessage: $copy->bannerMessage,
                    ),
                    $sessionTokenHash,
                );

                // Leaving the stub is not enough: every tab comes out onto the page it already had,
                // answered while the phase was still inactive, so the backup page would lack the
                // reopen block the banner tells the operator to use. Its pages are answered again.
                // After the resume and not before it, because a page is answered by the agent that
                // serves it, and while the agents stand nobody would (HIL-911). A tab opened under
                // the freeze is not reached by this: its subscribe never left the client, and the
                // tab sends it itself on the frame above (HIL-912).
                Hilos::$cluster?->protectedModeClientNotifier()?->reassessPagesOfSession($sessionTokenHash);
            } catch (InvalidArgumentException $exception) {
                Logger::error('Protected mode: failed to let a verifier into the window: ' . $exception->getMessage());
            }
        }
    }

    /**
     * Pushes the same verification frame again, now saying a pass is standing.
     *
     * The only announcement the mode makes without moving a phase, and it exists because the
     * verifier is normally already staring at the stub when the operator mints: the sentence turns
     * into the field with nothing clicked and nothing reloaded. The copy and the initiator
     * exclusion are the ones {@see enterVerifying()} used - the same frame, one bit later. The
     * exclusion is still worth making here, where the frame says active: it would put the operator
     * back on the stub in the one phase they are inside the application.
     */
    public function announcePassIssued(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $copy = ProtectedModeStubCopy::forOperation($view->operation);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(
                active: true,
                operation: $view->operation,
                title: $copy->title,
                message: $copy->message,
                acceptsPass: true,
                passIssued: true,
            ),
            $view->initiatorAcceptKey,
            $view->initiatorSessionTokenHash,
        );
    }

    /**
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function reenterActive(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        // Stop the agents the verification window brought back, naming the same initiator the row
        // still records - it is the one identity that keeps working through the freeze. A row that
        // names nobody would stop the initiator along with everything else, leaving no agent able
        // to lift the mode again, so this node says so and stays open rather than locking itself in.
        if ($view->initiatorAgentType === null) {
            Logger::warning('Protected mode: refusing to close back — no initiator identity is recorded');
            return;
        }

        // Write the phase before the stop, as enterActivating() does: the roster is stopped one
        // agent per master pass, and a signal handled between two of those passes would start an
        // agent the walk had already stopped - unless the agent-start gate is shut, and on
        // verifying it is open. Active shuts it, so the walk that follows runs behind a closed
        // gate from its first pass (HIL-1012). The write also voids every pass, which is what the
        // operator asked for.
        $view->actions->enterActive();
        $this->persistFreeze($view);

        Hilos::$cluster?->protectedModeAgentFreezer()?->stopAgentsForProtectedMode(
            $view->initiatorAgentType,
            $view->initiatorAgentIndex === null ? null : (string)$view->initiatorAgentIndex,
        );

        // Nobody is left out, the mirror of the entry above: the window is shut, the agents are
        // down again, and the operator goes back behind the stub together with everyone else. The
        // panel there is what they keep watching the operation from (HIL-718).
        $copy = ProtectedModeStubCopy::forOperation($view->operation);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(
                active: true,
                operation: $view->operation,
                title: $copy->title,
                message: $copy->message,
                acceptsPass: false,
                passIssued: false,
            ),
            null,
            null,
        );
    }

    /**
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterDeactivating(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $view->actions->enterDeactivating();
        $this->persistFreeze($view);
    }

    /**
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterInactive(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $view->actions->enterInactive();

        // Removed only once the row itself says inactive, and never before: a daemon that dies
        // between the two comes back holding a freeze that was already lifting, which the operator
        // lifts again in one command. The other order would open the node on a crash mid-lift.
        $this->forgetPersistedFreeze();

        // Bring back the agents stopped on entry (mirror of enterActivating's freeze) now the
        // freeze has lifted; the freezer replays exactly the set it stopped on this node.
        Hilos::$cluster?->protectedModeAgentFreezer()?->resumeAgentsForProtectedMode();
    }

    /**
     * The frame {@see enterInactive()} owes once the roster it asked for is back.
     */
    public function finishLift(): void
    {
        // Tell everyone the mode lifted, the initiator included - both halves of it, the socket
        // that asked and the browser behind it: after a restore its data is as stale as anybody
        // else's, and the frame means "reload". It carries no copy, because nothing renders words
        // on the way out.
        $lifted = new ProtectedModeStateSignalData(active: false);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState($lifted, null, null);
    }

    public function notifyInitiatorReady(): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        if ($view->initiatorAgentType === null) {
            Logger::warning('Protected mode: ready arrived but no initiator identity is recorded');
            return;
        }

        Hilos::$cluster?->protectedModeInitiatorRelay()?->deliverProtectedModeReady(
            $view->initiatorAgentType,
            $view->initiatorAgentIndex === null ? null : (string)$view->initiatorAgentIndex,
        );
    }

    /**
     * Leaves the row this node just wrote where a restarting daemon finds it.
     *
     * Written after the row and not before it, so what lands on disk is the phase that actually
     * took effect. The gap between the two writes is a fraction of a millisecond, while the outage
     * this file answers - a daemon killed during a restore - lasts minutes, so the direction of the
     * risk is the cheap one.
     *
     * A failure here is logged and the transition carries on. The freeze is already in the row and
     * already in force; what is lost is the ability to survive a restart, and taking the whole
     * transition down over it would abort a destructive operation that was going fine on a node
     * whose log directory has a problem.
     *
     * @param ProtectedModeRuntime $view Runtime singleton carrying the row just written
     */
    private function persistFreeze(ProtectedModeRuntime $view): void
    {
        try {
            $this->store->save($view->toArray());
        } catch (EnvException|FileMoveException|FileWriteException|JsonException $e) {
            Logger::error(
                'Protected mode: the freeze could not be left on disk, a restart would reopen this '
                . "node: {$e->getMessage()}",
            );
        }
    }

    /**
     * Removes the freeze left on disk, so a restart brings back an open node.
     *
     * Failure is logged rather than raised, as in {@see persistFreeze()} - and it errs the other
     * way: a file that outlives its freeze makes the next startup restore a freeze nobody is under,
     * which the watchdog reports at once and one operator command clears.
     */
    private function forgetPersistedFreeze(): void
    {
        try {
            $this->store->forget();
        } catch (EnvException|FileDeleteException $e) {
            Logger::error(
                'Protected mode: the lifted freeze could not be removed from disk, a restart would '
                . "bring it back: {$e->getMessage()}",
            );
        }
    }

    /**
     * Resolves the protected-mode runtime singleton, or null when this process holds no runtime state.
     *
     * Null is not a project opting out: the framework mounts this row for every project that has an
     * RT context at all, and both entries into the mode refuse before they reach the executor. What
     * is left here is defense in depth - an executor asked to freeze a node it cannot write to says
     * so and leaves the node open, instead of pretending it quiesced.
     *
     * @return ?ProtectedModeRuntime Runtime singleton view, or null when runtime state is unavailable
     */
    private function runtimeView(): ?ProtectedModeRuntime
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view !== null) {
            return $view;
        }

        Logger::warning('Protected mode: this process holds no runtime state, this node cannot freeze');

        return null;
    }
}
