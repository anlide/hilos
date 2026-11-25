<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Placement\PlacementRecovery;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\GroupOperationAuthority;

/**
 * One signal the master holds for one agent that is not up yet (HIL-629).
 *
 * The sibling of {@see ParkedSignal}, held at a different moment and for a different reason:
 * that one waits before routing for the identity behind a connection, this one waits after
 * routing for the agent the walk addressed - because that agent has no address anywhere yet, or
 * its start on this node has not been reported. Handing the frame to a worker at that moment is
 * how it used to die: a start that fails inside the worker takes every frame written behind it.
 *
 * Those two reasons end differently (HIL-1041). A start under way here ends in a fact the node
 * is going to hear - the start reported, the start refused, the worker gone - so the wait lasts
 * as long as the start does. An agent no node is known to host waits on a placement verdict:
 * the leader names a node, or it answers that it could not place it. Nothing here is a clock.
 *
 * A third reason, a connection's close held for an agent the freeze stopped (HIL-1208): it waits
 * for the lift to bring that agent back, then for its start report like any start under way; it
 * is dropped only when the walk passed the agent without starting it here.
 *
 * A policy-placed agent whose view names this node waits for the leader's start order (HIL-2121).
 * Once the order starts it here, the hold waits for the ordinary start report.
 *
 * A peer frame keeps its original address and normally delivers only here. A live recovery
 * can relay it once to a confirmed replacement host. A relay keeps that hop accounted for
 * across every repark. Link-bound uploads keep their ordinary local delivery lifetime.
 *
 * The whole signal is kept, not a closure over it, for the reason its sibling gives. It is held
 * for ONE agent, though, not for the signal's whole destination list: the walk has already
 * reached the other destinations, so a released frame goes to this agent alone, through the
 * same delivery door a frame that never waited goes through.
 */
final readonly class ParkedAgentSignal
{
    /**
     * @param SignalDTO $signal Signal as the walk was delivering it
     * @param string $agentId Agent the signal waits for
     * @param float $parkedAt Unix seconds the hold began at, which the release line reports the age from
     * @param bool $awaitingPlacement Whether the wait is for a placement verdict rather than a start here
     * @param bool $localOnly Whether this frame arrived already addressed over the peer mesh
     * @param bool $awaitingResume Whether the frame is a connection's close waiting for the protected-mode lift to bring its agent back
     * @param bool $awaitingPlacementOrder Whether this node awaits the leader's order to start the agent here
     * @param ?GroupOperationAuthority $groupOperation Original prepared authority, never recaptured at release
     * @param ?PlacementRecovery $recovery Replay obligation with its original placement
     * @param bool $placementRecoveryRelay Whether the frame already used its recovery hop
     * @param bool $linkBoundUpload Whether the bytes belong to an existing upload reservation
     * @param ?BrowserDeliveryTarget $actionClientTarget Original local socket for a proof-bearing action
     */
    public function __construct(
        public SignalDTO $signal,
        public string $agentId,
        public float $parkedAt,
        public bool $awaitingPlacement,
        public bool $localOnly = false,
        public bool $awaitingResume = false,
        public bool $awaitingPlacementOrder = false,
        public ?GroupOperationAuthority $groupOperation = null,
        public ?PlacementRecovery $recovery = null,
        public bool $placementRecoveryRelay = false,
        public bool $linkBoundUpload = false,
        public ?BrowserDeliveryTarget $actionClientTarget = null,
    ) {
    }

    /**
     * @param PlacementRecovery $recovery Live recovery observed after this frame was parked
     * @return self This original obligation with its replay basis
     */
    public function withRecovery(PlacementRecovery $recovery): self
    {
        return new self(
            $this->signal,
            $this->agentId,
            $this->parkedAt,
            false,
            $this->localOnly,
            false,
            false,
            $this->groupOperation,
            $recovery,
            $this->placementRecoveryRelay,
            $this->linkBoundUpload,
            $this->actionClientTarget,
        );
    }

    /**
     * @return self The same hold, waiting on a start here rather than a placement verdict
     */
    public function withoutPlacementWait(): self
    {
        return new self(
            $this->signal,
            $this->agentId,
            $this->parkedAt,
            false,
            $this->localOnly,
            $this->awaitingResume,
            $this->awaitingPlacementOrder,
            $this->groupOperation,
            $this->recovery,
            $this->placementRecoveryRelay,
            $this->linkBoundUpload,
            $this->actionClientTarget,
        );
    }

    /**
     * @return self The same hold, waiting on a start here rather than on the lift
     */
    public function withoutResumeWait(): self
    {
        return new self(
            $this->signal,
            $this->agentId,
            $this->parkedAt,
            $this->awaitingPlacement,
            $this->localOnly,
            false,
            $this->awaitingPlacementOrder,
            $this->groupOperation,
            $this->recovery,
            $this->placementRecoveryRelay,
            $this->linkBoundUpload,
            $this->actionClientTarget,
        );
    }

    /**
     * @return self The same hold, waiting on a start here rather than on the leader's order
     */
    public function withoutPlacementOrderWait(): self
    {
        return new self(
            $this->signal,
            $this->agentId,
            $this->parkedAt,
            $this->awaitingPlacement,
            $this->localOnly,
            $this->awaitingResume,
            false,
            $this->groupOperation,
            $this->recovery,
            $this->placementRecoveryRelay,
            $this->linkBoundUpload,
            $this->actionClientTarget,
        );
    }
}
