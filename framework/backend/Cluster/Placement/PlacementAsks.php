<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use SplPriorityQueue;

/** Exact ask lookup and bounded expiry index, private to one node's placement coordinator. */
final class PlacementAsks
{
    /** @var array<string, PlacementAsk> Current suppression incarnation per agent */
    private array $pending = [];

    /** @var SplPriorityQueue<float, PlacementAsk> Earliest deadline first, including retired scalar entries */
    private readonly SplPriorityQueue $expirations;

    /** Builds only process-local metadata; the coordinator supplies every clock value. */
    public function __construct()
    {
        $this->expirations = new SplPriorityQueue();
    }

    /**
     * Expiry is decided by address even when physical cleanup has not reached that entry.
     *
     * @param string $agentId Address being asked for
     * @param float $now Coordinator's current suppression-clock moment
     * @return bool Whether that address is still inside its original suppression interval
     */
    public function isPending(string $agentId, float $now): bool
    {
        return isset($this->pending[$agentId]) && $now < $this->pending[$agentId]->deadline;
    }

    /**
     * @param string $agentId Address whose retry shares this suppression window
     * @return ?float Original coordinator-clock deadline, absent when no ask is suppressed
     */
    public function deadlineOf(string $agentId): ?float
    {
        return $this->pending[$agentId]->deadline ?? null;
    }

    /**
     * @param string $agentId Address whose request is being sent
     * @param float $deadline Existing suppression deadline, calculated by the coordinator
     * @param ?string $leaderNodeId Destination of the request, if it has one
     * @param ?int $authorityTerm Authority of the destination, where a term is known
     * @param ?string $requestId Wire identity, absent for unsent asks
     */
    public function remember(
        string $agentId,
        float $deadline,
        ?string $leaderNodeId = null,
        ?int $authorityTerm = null,
        ?string $requestId = null,
    ): void {
        $ask = new PlacementAsk($agentId, $deadline, $leaderNodeId, $authorityTerm, $requestId);
        $this->pending[$agentId] = $ask;
        $this->expirations->insert($ask, -$deadline);
    }

    /**
     * @param string $agentId Address whose verdict arrived
     * @param ?string $leaderNodeId Leader currently responsible for the verdict
     * @param ?int $authorityTerm Leader's current term, when known
     * @return bool Whether this authority owns the existing ask
     */
    public function matchesAuthority(string $agentId, ?string $leaderNodeId, ?int $authorityTerm): bool
    {
        $ask = $this->pending[$agentId] ?? null;

        return $ask !== null && $ask->leaderNodeId === $leaderNodeId && $ask->authorityTerm === $authorityTerm;
    }

    /**
     * @param string $agentId Address whose verdict arrived
     * @param string $leaderNodeId Leader that answered
     * @param int $authorityTerm Term carried by the answer
     * @param string $requestId Exact ask answered
     * @param float $now Current suppression-clock moment
     * @return ?PlacementAsk Consumed request when the reply exactly answers a live ask
     */
    public function takeVerdict(string $agentId, string $leaderNodeId, int $authorityTerm, string $requestId, float $now): ?PlacementAsk
    {
        if (!$this->isPending($agentId, $now) || !$this->matchesAuthority($agentId, $leaderNodeId, $authorityTerm)
            || $this->pending[$agentId]->requestId !== $requestId) {
            return null;
        }
        $ask = $this->pending[$agentId];
        $this->forget($agentId);

        return $ask;
    }

    /** @param string $agentId Address explicitly canceled or answered negatively */
    public function forget(string $agentId): void
    {
        unset($this->pending[$agentId]);
    }

    /**
     * Every heap extraction spends one visit, including a canceled or replaced incarnation.
     * Only scalar ask metadata is retained by the heap; it never owns a waiting signal.
     *
     * @param float $now Coordinator's current suppression-clock moment
     * @param int $steps Maximum physical expired entries to release in this call
     */
    public function expire(float $now, int $steps): void
    {
        for ($step = 0; $step < $steps && !$this->expirations->isEmpty(); $step++) {
            $ask = $this->expirations->top();
            if ($now < $ask->deadline) {
                return;
            }
            $this->expirations->extract();
            if (($this->pending[$ask->agentId] ?? null) === $ask) {
                unset($this->pending[$ask->agentId]);
            }
        }
    }
}
