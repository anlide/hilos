<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
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
 * (freezing itself) and every follower own one, and both release the same way. A node that never
 * mounted the runtime item writes nothing and says so in the log, so the executor is inert there
 * rather than failing.
 *
 * {@see notifyInitiatorReady()} relays the leader's ready to the initiator agent by addressing the
 * worker hosting it through {@see ProtectedModeReadyRelay}, reading the initiator identity back from
 * the runtime row this node wrote on entry. On entry it stops this node's own agents through
 * {@see ProtectedModeAgentFreezer}, leaving the initiator agent running; on exit ({@see enterInactive()})
 * the same freezer brings back exactly the agents it stopped.
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
     * Resolves the protected-mode runtime singleton, or null when this node never mounted it.
     *
     * An executor asked to freeze a node that carries no runtime row cannot freeze it, and the
     * caller has already decided the operation needs protecting - so the miss is logged rather
     * than passed over in silence, and the node stays open instead of pretending it quiesced.
     *
     * @return ?ProtectedModeRuntime Runtime singleton view, or null when it is not mounted
     */
    private function runtimeView(): ?ProtectedModeRuntime
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view !== null) {
            return $view;
        }

        Logger::warning(
            'Protected mode: the runtime singleton is not mounted, this node cannot freeze;'
            . ' mount it in the project RtContext',
        );

        return null;
    }
}
