<?php

declare(strict_types=1);

namespace Hilos\Cluster\Probe;

use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Daemon\AgentDeclarationDuty;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Environment\Exception\EnvException;
use Generator;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Throwable;

/**
 * ProbeFleetSupervisor - the leader's keeper of the cluster probe fleet ({@see FleetProbeAgent}).
 *
 * While this node leads, it hands every fleet member to the framework's best-fit policy (HIL-182,
 * HIL-448). A member declares the worker capability and no project cost, so placement charges it
 * its one mandatory slot (HIL-447): the policy gates on the tag, on a node having declared some
 * capacity and on a free slot, and then spreads the fleet by each node's share of the slots it
 * declares, the leader last among equal shares; the strongest node is a tie-breaker far down that
 * list, not the target. Failover defaults (HIL-183) re-place the lost node's share when a node
 * dies. Placement is idempotent per member - a tracked record, in any state, suppresses
 * re-placing - so the leader never double-runs a member, and a fresh leader re-derives the fleet
 * from the mesh.
 *
 * Its own pass beside the framework's policy sweep because that sweep leaves indexed pools alone:
 * how many members a pool has is known only to whoever declared it, and this pool is the
 * framework's ({@see ClusterProbe::FLEET_SIZE}). It does nothing where the fleet is not listed in
 * the project's AGENTS or where no probe may start ({@see ClusterProbe::mayRunHere()}).
 *
 * A fresh leader's placements wait for its rebuild - every node's report, or its failover grace
 * ({@see ClusterPlacement}, HIL-1217) - so a leader inheriting a placed member adopts it instead
 * of placing a copy. The wait is the placement coordinator's, not this pass's: it waits for the
 * fact rather than for a number of heartbeats.
 *
 * Held by the {@see DaemonManager} and driven from its master loop, by the block that runs only
 * on the leader.
 */
final class ProbeFleetSupervisor
{
    /** New declaration pacing after an exception before any record exists; placement retries stay separate. */
    private const float DECLARATION_RETRY_SECONDS = 5.0;

    private AgentDeclarationDuty $declarations;
    private ?string $appClass = null;
    private bool $leader = true;

    /** The fleet's debt has no reader outside this master and owns no shared runtime rows. */
    public function __construct()
    {
        $this->declarations = new AgentDeclarationDuty(self::DECLARATION_RETRY_SECONDS);
    }

    /**
     * @param EventLoop $loop Existing master's native base
     * @throws EventLoopException When an original declaration retry cannot bind
     */
    public function attachEventLoop(EventLoop $loop): void
    {
        $this->declarations->attachEventLoop($loop);
    }

    /** Cancels activated and future retries on terminal disposal. */
    public function detachEventLoop(): void
    {
        $this->declarations->detachEventLoop();
    }

    /** Leadership loss is observed before the coordinator replaces its old role. */
    public function suspend(): void
    {
        $this->declarations->suspend();
    }

    /**
     * @param bool $leader Current guarded role and exit verdict
     * @throws EnvException When probe eligibility or cluster mode cannot be read
     */
    public function reconcile(bool $leader): void
    {
        $this->leader = $leader;
        if ($this->appClass !== Hilos::appClass()) {
            $this->appClass = Hilos::appClass();
            $this->declarations->replaceTargets($this->targets());
        }
        $this->declarations->reconcile(null, $leader
            && isset(Hilos::appClass()::AGENTS[HilosAgentType::HILOS_PROBE_FLEET]) && ClusterProbe::mayRunHere());
    }

    /** @param ?string $agentId Actual changed address, null for capacity or completed rebuild */
    public function noteChanged(?string $agentId): void
    {
        $this->declarations->noteChanged($agentId);
    }

    /** @return bool Finite declaration demand or own due retry, never an accepted rebuild wait */
    public function hasReadyWork(): bool
    {
        return $this->declarations->hasReadyWork();
    }

    /**
     * Compatible leader-guarded business entry. Stable calls never walk the registry or fleet.
     *
     * @throws EventLoopException When native scheduling fails, outside business refusal containment
     */
    public function tick(): void
    {
        try {
            $this->reconcile($this->leader);
            $this->declarations->dispatch();
        } catch (EventLoopException $e) {
            throw $e;
        } catch (Throwable $e) {
            Logger::warning("Could not place the cluster probe fleet: {$e->getMessage()}");
        }
    }

    /** @return Generator<string, AgentId> Exactly the framework-owned fleet, with no extra indexes */
    private function targets(): Generator
    {
        if (!isset(Hilos::appClass()::AGENTS[HilosAgentType::HILOS_PROBE_FLEET])) {
            return;
        }
        for ($index = 0; $index < ClusterProbe::FLEET_SIZE; $index++) {
            $agentIndex = (string)$index;
            yield HilosAgentType::HILOS_PROBE_FLEET . ':' . $agentIndex => new AgentId(HilosAgentType::HILOS_PROBE_FLEET, $agentIndex);
        }
    }
}
