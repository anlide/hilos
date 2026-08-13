<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Utils\Logger;

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
 * field - the stub has to stay up for everyone who holds no pass.
 */
final class DaemonProtectedModeExecutor implements ProtectedModeExecutor
{
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
        $this->runtimeView()?->actions->enterActive();
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

        Hilos::$cluster?->protectedModeAgentFreezer()?->resumeAgentsForProtectedMode();

        // The stub stays up for everyone without a pass, so the frame still says active: what it
        // adds is that this surface may now offer a code field. The initiator is left out for the
        // same reason as on entry - it has been seeing the real app all along.
        $copy = ProtectedModeStubCopy::forOperation($view->operation);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(
                active: true,
                operation: $view->operation,
                title: $copy->title,
                message: $copy->message,
                acceptsPass: true,
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

        $copy = ProtectedModeStubCopy::forOperation($view->operation);
        Hilos::$cluster?->protectedModeClientNotifier()?->notifyProtectedModeState(
            new ProtectedModeStateSignalData(
                active: true,
                operation: $view->operation,
                title: $copy->title,
                message: $copy->message,
                acceptsPass: false,
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
        $this->runtimeView()?->actions->enterDeactivating();
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
