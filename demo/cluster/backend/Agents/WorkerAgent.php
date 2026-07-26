<?php

declare(strict_types=1);

namespace Demo\Cluster\Agents;

use Demo\Cluster\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Utils\Logger;

/**
 * WorkerAgent - one placeable unit of synthetic data-plane work.
 *
 * The leader keeps a fleet of these placed across the nodes advertising the WORKER
 * capability, so the harness has real work to shuffle: a failover moves a whole
 * node's share of the fleet rather than a single no-op marker. Each instance busies
 * its monopolistic worker with short jobs and reports its throughput, which is what
 * makes a node's share of the load visible in the logs.
 *
 * The per-job sleep deliberately breaks the "never block in onTick" rule
 * (docs/agents/agent-system/ontick-rule.md): occupying the worker IS the workload
 * being simulated. It stays confined to this harness demo and must not be copied
 * into an application agent.
 */
final class WorkerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::WORKER;

    /** @var int Shortest synthetic job, in microseconds */
    private const int JOB_MIN_USEC = 50000;

    /** @var int Longest synthetic job, in microseconds */
    private const int JOB_MAX_USEC = 250000;

    /** @var float Seconds between throughput reports */
    private const float REPORT_INTERVAL_SEC = 5.0;

    /** @var int Jobs finished since the last report */
    private int $jobsDone = 0;

    /** @var float Microtime the next throughput report is due */
    private float $reportDueAt = 0.0;

    /**
     * @param string $agentIndex Fleet member index this instance carries
     * @throws AgentIndexRequiredException When the fleet member index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('WorkerAgent requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /**
     * Logs that this unit of work came up on this data-plane node and arms the report.
     */
    public function onStart(): void
    {
        $this->reportDueAt = microtime(true) + self::REPORT_INTERVAL_SEC;

        Logger::info("Worker {$this->getId()} started on this node: it is now carrying load");
    }

    /**
     * Runs one synthetic job, then reports throughput once per report interval.
     */
    public function onTick(): void
    {
        usleep(mt_rand(self::JOB_MIN_USEC, self::JOB_MAX_USEC));
        $this->jobsDone++;

        $now = microtime(true);
        if ($now < $this->reportDueAt) {
            return;
        }

        Logger::info("Worker {$this->getId()} finished {$this->jobsDone} job(s) in the last "
            . self::REPORT_INTERVAL_SEC . 's');
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

}
