<?php

declare(strict_types=1);

namespace Hilos\Cluster\Probe;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Utils\Logger;

/**
 * FleetProbeAgent - one placeable unit of synthetic data-plane work.
 *
 * The leader keeps a fleet of these placed across the nodes advertising the WORKER
 * capability ({@see ProbeFleetSupervisor}), so the harness has real work to shuffle: a
 * failover moves a whole node's share of the fleet rather than a single no-op marker. Each
 * instance busies its worker with short jobs and reports its throughput, which is what makes a
 * node's share of the load visible in the logs.
 *
 * The per-job sleep deliberately breaks the "never block in onTick" rule
 * (docs/agents/agent-system/ontick-rule.md): occupying the worker IS the workload
 * being simulated. It never starts outside a clustered non-production node
 * ({@see ClusterProbe::mayRunHere()}) and must not be copied into an application agent.
 *
 * It carries no protected-mode drive: the index agent of a full project declares those
 * commands, and one command declared by two agents refuses the start.
 */
final class FleetProbeAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_PROBE_FLEET;

    /**
     * @var array<string, list<TruthSourceOperation>> The status row of this fleet member alone, and
     *     that is the whole point of the fleet: every node runs members of the same collection,
     *     each owning its own row, so the collection converges across the mesh without any node
     *     ever claiming another's row (HIL-589). Which row is the instance's business
     *     ({@see self::ownedRtRowKeys()}).
     */
    public const array OWNS_RT_ROWS = [HilosProbeFleetStatus::RT_COLLECTION => TruthSourceOperation::BY_KIND];

    /** @var int Shortest synthetic job, in microseconds */
    private const int JOB_MIN_USEC = 50000;

    /** @var int Longest synthetic job, in microseconds */
    private const int JOB_MAX_USEC = 250000;

    /** @var float Seconds between throughput reports */
    private const float REPORT_INTERVAL_SEC = 5.0;

    /** @var int Jobs finished since the last report */
    private int $jobsDone = 0;

    /** @var int Jobs finished since this instance started, as the runtime row reports them */
    private int $jobsDoneTotal = 0;

    /** @var float Microtime the next throughput report is due */
    private float $reportDueAt = 0.0;

    /**
     * @param string $agentIndex Fleet member index this instance carries
     * @throws AgentIndexRequiredException When the fleet member index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('FleetProbeAgent requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /**
     * @param string $collection Collection the resolver is asking about
     * @return list<string> The status row of this fleet member, keyed by its index
     */
    public function ownedRtRowKeys(string $collection): array
    {
        return [(string)$this->agentIndex];
    }

    /**
     * Publishes this member's row and arms the report.
     *
     * @throws HilosException When the first report cannot be written
     */
    public function onStart(): void
    {
        $this->reportDueAt = microtime(true) + self::REPORT_INTERVAL_SEC;
        $this->report();

        Logger::info("Worker {$this->getId()} started on this node: it is now carrying load");
    }

    /**
     * Runs one synthetic job, then reports throughput once per report interval.
     *
     * @throws HilosException When the throughput report cannot be written
     */
    public function onTick(): void
    {
        usleep(mt_rand(self::JOB_MIN_USEC, self::JOB_MAX_USEC));
        $this->jobsDone++;
        $this->jobsDoneTotal++;

        $now = microtime(true);
        if ($now < $this->reportDueAt) {
            return;
        }

        Logger::info("Worker {$this->getId()} finished {$this->jobsDone} job(s) in the last "
            . self::REPORT_INTERVAL_SEC . 's');
        $this->report();
        $this->jobsDone = 0;
        $this->reportDueAt = $now + self::REPORT_INTERVAL_SEC;
    }

    /**
     * Logs that this unit of work left the node; it owns nothing else to clear.
     */
    public function onStop(): void
    {
        Logger::info("Worker {$this->getId()} stopped on this node: that much load moved away");
    }

    /**
     * Writes this member's row: what it has done, and how much of the fleet it can see.
     *
     * The second number is the one an acceptance run cannot get any other way. The inspect
     * command reads the master's copy, so it proves a write reached the other node's MASTER; a
     * count taken here, in the worker, is the only thing that says the write reached the other
     * node's WORKERS - the processes an application actually reads runtime state from.
     *
     * @throws HilosException When a subscriber to the collection's announcement raises
     */
    private function report(): void
    {
        // A project without a runtime context has no collection to report into; the member still
        // carries its load, which is the half of its job that needs nothing mounted.
        $statuses = Hilos::$rt?->hilosProbeFleetStatuses;
        if ($statuses === null) {
            return;
        }

        $statuses->actions->report($this->agentIndex, $this->jobsDoneTotal, count($statuses));
    }
}
