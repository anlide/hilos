<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeRequest;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\NonProductionGate;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerTestAccountErasureProbeDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountErasureProbedDTO;
use Throwable;

/** Process-private console waits bound to the exact original local worker connection. */
final class AccountErasureProbeTestRequests
{
    /** @var array<string, WorkerClient> Original connections, keyed by console correlation */
    private array $workers = [];
    /** @var array<string, int> Original signed placement ids, keyed by correlation */
    private array $workerIds = [];
    /** @var array<string, string> Source addresses, keyed by correlation */
    private array $agentIds = [];
    /** @var array<string, float> Original monotonic deadlines, keyed by correlation */
    private array $deadlines = [];
    private float $nextDeadline = INF;
    private ?EventLoopTimer $deadlineTimer = null;

    /**
     * @param AgentManagerDaemon $agents Master's local placement roster
     * @param Closure(): ?WorkerServer $workerServer Existing worker transport
     * @param Closure(CommandReplyDTO): void $reply Reply to the held console
     */
    public function __construct(
        private readonly AgentManagerDaemon $agents,
        private readonly Closure $workerServer,
        private readonly Closure $reply,
    ) {
    }

    /**
     * The timer only cues the master's guarded deadline phase.
     *
     * @param EventLoop $loop Master's native loop
     * @param Closure(): void $wake Guarded-phase readiness cue
     * @throws EventLoopException When an original live deadline cannot be armed
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        $this->detachEventLoop();
        $this->deadlineTimer = $loop->createTimer($wake);
        $this->rearmDeadlineTimer();
    }

    /** Detaches native work while retaining original live waits for rebind. */
    public function detachEventLoop(): void
    {
        $this->deadlineTimer?->close();
        $this->deadlineTimer = null;
    }

    /**
     * Validates and routes only to an existing local worker; never starts or moves a source.
     *
     * @param CommandRequestDTO $request Held request, independently validated here
     * @param ?float $now Monotonic seconds, injectable for deadline tests
     * @throws EventLoopException When the nearest original deadline cannot be armed or canceled
     */
    public function request(CommandRequestDTO $request, ?float $now = null): void
    {
        if (!NonProductionGate::admitted()) {
            ($this->reply)(CommandReplyDTO::error(
                $request->correlationId,
                TestOnlyCommandOnProductionException::message(CliCommands::ACCOUNT_TEST_ERASURE_PROBE),
            ));
            return;
        }
        try {
            $probe = AccountErasureProbeRequest::fromArray($request->payload);
            $workerId = $this->agents->getAgentWorkerId($probe->agentId);
            $server = ($this->workerServer)();
            $worker = $workerId === null ? null : $server?->sendTestAccountErasureProbe(
                $workerId,
                new WorkerTestAccountErasureProbeDTO($request->correlationId, $probe),
            );
            if ($worker === null) {
                ($this->reply)(CommandReplyDTO::error($request->correlationId, "No agent {$probe->agentId} runs on this node."));
                return;
            }
            $this->workers[$request->correlationId] = $worker;
            $this->workerIds[$request->correlationId] = $workerId;
            $this->agentIds[$request->correlationId] = $probe->agentId;
            $this->deadlines[$request->correlationId] = ($now
                ?? hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND)
                + CommandChannelWindows::AGENT_WAIT_SECONDS;
        } catch (InvalidFormatException $failure) {
            ($this->reply)(CommandReplyDTO::error($request->correlationId, $failure->getMessage()));
        } catch (Throwable) {
            ($this->reply)(CommandReplyDTO::error($request->correlationId, 'Could not reach the local erasure probe worker.'));
        }
        $this->rearmDeadlineTimer();
    }

    /**
     * Unknown, duplicate and foreign acknowledgements cannot consume another connection's wait.
     *
     * @param WorkerClient $worker Actual sender connection
     * @param WorkerTestAccountErasureProbedDTO $ack Parsed outcome
     * @param ?float $now Monotonic seconds, injectable for deadline tests
     * @throws EventLoopException When the nearest original deadline cannot be armed or canceled
     */
    public function accept(WorkerClient $worker, WorkerTestAccountErasureProbedDTO $ack, ?float $now = null): void
    {
        $correlationId = $ack->correlationId;
        if (($this->workers[$correlationId] ?? null) !== $worker) {
            return;
        }
        if (($now ?? hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND)
            >= $this->deadlines[$correlationId]) {
            $this->refuse($correlationId, $this->lateRefusal($correlationId));
            return;
        }
        if ($worker->shouldClose()) {
            $this->workerGone($worker);
            return;
        }
        if ($this->agents->getAgentWorkerId($this->agentIds[$correlationId]) !== $this->workerIds[$correlationId]) {
            $this->refuse($correlationId, 'The erasure probe source no longer runs on the original worker.');
            return;
        }
        $this->abandon($correlationId);
        ($this->reply)($ack->error !== null
            ? CommandReplyDTO::error($correlationId, $ack->error)
            : CommandReplyDTO::ok($correlationId, $ack->state->toArray()));
    }

    /**
     * @param WorkerClient $worker Original worker connection that closed
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    public function workerGone(WorkerClient $worker): void
    {
        foreach (array_keys($this->workers, $worker, true) as $correlationId) {
            $this->refuse((string)$correlationId, 'The erasure probe worker went down before acknowledging the request.');
        }
    }

    /**
     * @param string $correlationId Request whose caller left or whose answer arrived
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    public function abandon(string $correlationId): void
    {
        if (!isset($this->workers[$correlationId])) {
            return;
        }
        unset($this->workers[$correlationId], $this->workerIds[$correlationId],
            $this->agentIds[$correlationId], $this->deadlines[$correlationId]);
        $this->rearmDeadlineTimer();
    }

    /**
     * @param ?float $now Monotonic seconds, injectable for deadline tests
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    public function tick(?float $now = null): void
    {
        $now ??= hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        if ($now < $this->nextDeadline) {
            $this->rearmDeadlineTimer();
            return;
        }
        foreach ($this->deadlines as $correlationId => $deadline) {
            if ($now >= $deadline) {
                $this->refuse((string)$correlationId, $this->lateRefusal((string)$correlationId));
            }
        }
    }

    /**
     * @param string $correlationId Request still waiting for the original worker
     * @return string Refusal naming its source
     */
    private function lateRefusal(string $correlationId): string
    {
        return "Agent {$this->agentIds[$correlationId]} did not acknowledge the erasure probe in time.";
    }

    /**
     * @param string $correlationId Wait to consume before answering
     * @param string $error Safe refusal for the console
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    private function refuse(string $correlationId, string $error): void
    {
        $this->abandon($correlationId);
        ($this->reply)(CommandReplyDTO::error($correlationId, $error));
    }

    /** @throws EventLoopException When the nearest live deadline cannot be armed or canceled */
    private function rearmDeadlineTimer(): void
    {
        $this->nextDeadline = $this->deadlines === [] ? INF : min($this->deadlines);
        $this->deadlineTimer?->armAt(is_finite($this->nextDeadline) ? $this->nextDeadline : null);
    }
}
