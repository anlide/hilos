<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

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
 * worker hosting it through {@see ProtectedModeReadyRelay}, reading the initiator identity back from
 * the runtime row this node wrote on entry. On entry it stops this node's own agents through
 * {@see ProtectedModeAgentFreezer}, leaving the initiator agent running; on exit ({@see enterInactive()})
 * the same freezer brings back exactly the agents it stopped.
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
     * @param ?string $initiatorAcceptKey Accept key let through when the leader freezes itself; null on a follower
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function enterActivating(ProtectedModeQuiesceData $freeze, ?string $initiatorAcceptKey): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $view->actions->enterActivating($freeze, $initiatorAcceptKey);
        $this->persistFreeze($view);

        // Stop this node's own agents so no application work runs against the destructive
        // operation, leaving the initiator agent running to carry it out (HIL-267 slice 7a).
        Hilos::$cluster?->protectedModeAgentFreezer()?->stopAgentsForProtectedMode(
            $freeze->initiatorAgentType,
            $freeze->initiatorAgentIndex === null ? null : (string)$freeze->initiatorAgentIndex,
        );

        // Tell the connections that were already open: the lockdown is binary from this phase on,
        // so this is the earliest honest moment, and on a follower the phase never gets past it.
        // The initiator's own connection is left out - it must keep seeing the real app.
        $copy = ProtectedModeStubCopy::forOperation($freeze->operation);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(
                active: true,
                operation: $freeze->operation,
                title: $copy->title,
                message: $copy->message,
            ),
            $initiatorAcceptKey,
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
        // The initiator is left out for the same reason as on entry - it has been seeing the real
        // app all along.
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
        );
    }

    /**
     * Pushes the same verification frame again, now saying a pass is standing.
     *
     * The only announcement the mode makes without moving a phase, and it exists because the
     * verifier is normally already staring at the stub when the operator mints: the sentence turns
     * into the field with nothing clicked and nothing reloaded. The copy and the initiator
     * exclusion are the ones {@see enterVerifying()} used - the same frame, one bit later.
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

        Hilos::$cluster?->protectedModeAgentFreezer()?->stopAgentsForProtectedMode(
            $view->initiatorAgentType,
            $view->initiatorAgentIndex === null ? null : (string)$view->initiatorAgentIndex,
        );

        // Write the phase after the stop, the mirror of the order enterVerifying() needs: the
        // agent-start gate is closed on active, so a stop ordered under it cannot race a restart.
        // The write also voids every pass, which is what the operator asked for.
        $view->actions->enterActive();
        $this->persistFreeze($view);

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
            $view->initiatorAcceptKey,
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

        // Tell everyone the mode lifted, the initiator included: after a restore its data is as
        // stale as anybody else's, and the frame means "reload". It carries no copy, because
        // nothing renders words on the way out.
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(active: false),
            null,
        );
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

        Hilos::$cluster?->protectedModeReadyRelay()?->deliverProtectedModeReady(
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
