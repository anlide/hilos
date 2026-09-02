<?php

declare(strict_types=1);

namespace Demo\Cluster\Agents;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Hilos;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\ProtectedModeTestDriverTrait;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
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
    use ProtectedModeTestDriverTrait;

    public const string AGENT_TYPE = AgentType::WORKER;

    /**
     * The protected-mode drive trio (HIL-344, HIL-481), carried here as well as on the Hilos
     * index agent.
     *
     * This demo is headless and has no Hilos index, so without a carrier of its own the
     * clustered entry path - the leader's quiesce/quiesced round and the fail-closed refusal a
     * follower gives - would be unreachable from a live run and stay proven by unit tests
     * alone. That path is exactly what this harness exists to exercise.
     *
     * The inspector is not listed: it is answered by the master, not by an agent.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::PROTECTED_MODE_TEST_ENTER,
        CliCommands::PROTECTED_MODE_TEST_LEAVE,
        CliCommands::PROTECTED_MODE_TEST_OPEN,
    ];

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
            throw new AgentIndexRequiredException('WorkerAgent requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /**
     * Takes ownership of this member's row, publishes it, and arms the report.
     *
     * The claim names one key - this member's index - and that is the whole point of the fleet
     * here: every node runs members of the same collection, each owning its own rows, so the
     * collection converges across the mesh without any node ever claiming another's row
     * (HIL-589).
     *
     * @throws HilosException When the first report cannot be written
     */
    public function onStart(): void
    {
        $this->reportDueAt = microtime(true) + self::REPORT_INTERVAL_SEC;
        $this->registerRtTruthSource(ClusterRtContext::workerStatuses, [$this->agentIndex]);
        $this->report();

        Logger::info("Worker {$this->getId()} started on this node: it is now carrying load");
    }

    /**
     * Finishes any protected-mode drive in flight, runs one synthetic job, then reports
     * throughput once per report interval.
     *
     * The drive is served before the synthetic job, not after: the job blocks this worker for
     * up to a quarter of a second, and the drive's own wait window is measured in seconds.
     *
     * @throws HilosException When the throughput report cannot be written
     */
    public function onTick(): void
    {
        $this->tickProtectedModeTestDriver();

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
        Hilos::$rt->workerStatuses->actions->report(
            $this->agentIndex,
            $this->jobsDoneTotal,
            count(Hilos::$rt->workerStatuses),
        );
    }

    /**
     * Routes the command-channel commands declared in {@see AGENT_COMMANDS}.
     *
     * Every path answers exactly once, so a CLI parked on the command socket learns the
     * outcome instead of timing out.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if (!$this->isProtectedModeTestCommand($data->command)) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

            return;
        }

        $this->handleProtectedModeTestCommand($data);
    }

    /**
     * Logs that this unit of work left the node; it owns nothing else to clear.
     */
    public function onStop(): void
    {
        Logger::info("Worker {$this->getId()} stopped on this node: that much load moved away");
    }

}
