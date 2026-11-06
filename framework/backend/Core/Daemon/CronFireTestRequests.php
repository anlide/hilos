<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\Cron\CronScheduleTestOperation;
use Hilos\Environment\NonProductionGate;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerTestCronFireDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleResultDTO;
use Throwable;

/**
 * Local console waits for an agent's cron rule fired once now, each answered by the worker running the agent.
 *
 * The master arms nothing itself: it hands the ask to the worker its roster names for the agent,
 * over the existing link, and waits for that connection's outcome. The worker sends it after the
 * agent's tick in which the armed rule fired, behind that tick's cron report, so the node's Daemon
 * agent has the new run before the console has its answer.
 *
 * A wait ends four ways: the outcome arrived, the deadline passed, the worker's connection went,
 * the caller left. Nothing here touches the database, a file or the network.
 */
final class CronFireTestRequests
{
    /** Refusal of a payload that names no rule, or an owner that is neither an agent id nor null. */
    public const string INVALID_PAYLOAD = 'test:cron:fire needs agentId (string or null) and ruleName';

    /**
     * @var array<string, WorkerClient> Connection each pending ask went out on, by console correlation.
     *     A correlation of digits only is stored under an int key, so a key read back is cast to string.
     */
    private array $workers = [];

    /** @var array<string, string> Agent whose rule each pending ask fires, by console correlation */
    private array $agentIds = [];

    /** @var array<string, string> Rule each pending ask fires, by console correlation */
    private array $ruleNames = [];

    /** @var array<string, WorkerTestCronScheduleDTO> Schedule mutations sharing the same connection-bound wait */
    private array $schedules = [];

    /** @var array<string, float> Last moment each pending ask may still be answered, by console correlation */
    private array $deadlines = [];

    private float $nextDeadline = INF;

    /**
     * @param AgentManagerDaemon $agents This master's agent roster, asked which worker runs the agent
     * @param Closure(): ?WorkerServer $workerServer This master's worker transport
     * @param Closure(CommandReplyDTO): void $reply Delivery to the held local console
     */
    public function __construct(
        private readonly AgentManagerDaemon $agents,
        private readonly Closure $workerServer,
        private readonly Closure $reply,
    ) {
    }

    /**
     * Sends the arming to the worker running the agent, or refuses the ask at once.
     *
     * @param CommandRequestDTO $request Held request, independently checked at this boundary
     * @param string $agentId Agent holding the rule
     * @param string $ruleName Rule to fire
     * @param ?float $now Current seconds, injectable for deadline tests
     * @param ?WorkerTestCronScheduleDTO $schedule Immediate pause mutation instead of a firing request
     */
    public function request(
        CommandRequestDTO $request,
        string $agentId,
        string $ruleName,
        ?float $now = null,
        ?WorkerTestCronScheduleDTO $schedule = null,
    ): void
    {
        if (!NonProductionGate::admitted()) {
            ($this->reply)(CommandReplyDTO::error(
                $request->correlationId,
                TestOnlyCommandOnProductionException::message($request->command),
            ));
            return;
        }

        try {
            $workerId = $this->agents->getAgentWorkerId($agentId);
            $server = ($this->workerServer)();
            $worker = $workerId === null ? null : ($schedule === null
                ? $server?->sendTestCronFire($workerId, new WorkerTestCronFireDTO($request->correlationId, $agentId, $ruleName))
                : $server?->sendTestCronSchedule($workerId, $schedule));
            if ($worker === null) {
                ($this->reply)(CommandReplyDTO::error($request->correlationId, "No agent {$agentId} runs on this node."));
                return;
            }
            $deadline = ($now ?? microtime(true)) + CommandChannelWindows::AGENT_WAIT_SECONDS;
            $this->workers[$request->correlationId] = $worker;
            $this->agentIds[$request->correlationId] = $agentId;
            $this->ruleNames[$request->correlationId] = $ruleName;
            if ($schedule !== null) {
                $this->schedules[$request->correlationId] = $schedule;
            }
            $this->deadlines[$request->correlationId] = $deadline;
            $this->nextDeadline = min($this->nextDeadline, $deadline);
        } catch (Throwable $failure) {
            ($this->reply)(CommandReplyDTO::error($request->correlationId, $failure->getMessage()));
        }
    }

    /**
     * Unknown, duplicate and foreign-connection frames leave waiting untouched.
     *
     * @param WorkerClient $worker Actual sender connection
     * @param WorkerTestCronFiredDTO|WorkerTestCronScheduleResultDTO $ack Parsed firing or schedule outcome
     * @param ?float $now Current seconds, injectable for deadline tests
     */
    public function accept(
        WorkerClient $worker,
        WorkerTestCronFiredDTO|WorkerTestCronScheduleResultDTO $ack,
        ?float $now = null,
    ): void
    {
        $correlationId = $ack->correlationId;
        if (($this->workers[$correlationId] ?? null) !== $worker
            || (isset($this->schedules[$correlationId]) !== ($ack instanceof WorkerTestCronScheduleResultDTO))) {
            return;
        }
        if (($now ?? microtime(true)) >= $this->deadlines[$correlationId]) {
            $this->refuse($correlationId, $this->lateRefusal($correlationId));
            return;
        }
        if ($worker->shouldClose()) {
            $this->workerGone($worker);
            return;
        }
        if ($ack->error !== null) {
            $this->refuse($correlationId, $ack->error);
            return;
        }
        if ($ack instanceof WorkerTestCronFiredDTO) {
            $payload = [CommandConstants::FIELD_FIRED_AT => $ack->firedAt];
        } else {
            $schedule = $this->schedules[$correlationId];
            $payload = $schedule->operation === CronScheduleTestOperation::Pause
                ? [CommandConstants::FIELD_PAUSE_ID => $schedule->pauseId]
                : [];
        }
        $this->abandon($correlationId);
        ($this->reply)(CommandReplyDTO::ok($correlationId, $payload));
    }

    /** @param WorkerClient $worker Original connection that closed */
    public function workerGone(WorkerClient $worker): void
    {
        foreach (array_keys($this->workers, $worker, true) as $key) {
            $correlationId = (string)$key;
            $this->refuse(
                $correlationId,
                isset($this->schedules[$correlationId])
                    ? "The worker running agent {$this->agentIds[$correlationId]} went down before the schedule change was acknowledged."
                    : "The worker running agent {$this->agentIds[$correlationId]} went down before the rule fired.",
            );
        }
    }

    /** @param string $correlationId Ask whose caller stopped waiting, or that has been answered */
    public function abandon(string $correlationId): void
    {
        unset(
            $this->workers[$correlationId],
            $this->agentIds[$correlationId],
            $this->ruleNames[$correlationId],
            $this->schedules[$correlationId],
            $this->deadlines[$correlationId],
        );
        if ($this->workers === []) {
            $this->nextDeadline = INF;
        }
    }

    /** @param float $now Current seconds on the master's loop */
    public function tick(float $now): void
    {
        if ($now < $this->nextDeadline) {
            return;
        }
        $this->nextDeadline = INF;
        foreach ($this->deadlines as $correlationId => $deadline) {
            if ($now >= $deadline) {
                $this->refuse((string)$correlationId, $this->lateRefusal((string)$correlationId));
            } else {
                $this->nextDeadline = min($this->nextDeadline, $deadline);
            }
        }
    }

    /**
     * @param string $correlationId Pending ask whose agent has not checked the rule
     * @return string Refusal naming the agent and the rule
     */
    private function lateRefusal(string $correlationId): string
    {
        if (isset($this->schedules[$correlationId])) {
            return "Agent {$this->agentIds[$correlationId]} did not acknowledge cron schedule {$this->ruleNames[$correlationId]} in time.";
        }
        return "Agent {$this->agentIds[$correlationId]} did not check cron rule {$this->ruleNames[$correlationId]} in time.";
    }

    /**
     * @param string $correlationId Wait to consume before replying
     * @param string $error Refusal the held console is answered with
     */
    private function refuse(string $correlationId, string $error): void
    {
        $this->abandon($correlationId);
        ($this->reply)(CommandReplyDTO::error($correlationId, $error));
    }
}
