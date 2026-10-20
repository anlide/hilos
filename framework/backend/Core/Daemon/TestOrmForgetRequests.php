<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Database\Database;
use Hilos\Database\OrmTestConstants;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgetDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgottenDTO;
use Throwable;

/** Local console waits, discarded on acknowledgement, loss, timeout or caller departure. */
final class TestOrmForgetRequests
{
    /** @var array<string, TestOrmForgetRequest> Pending requests by console correlation */
    private array $pending = [];
    private float $nextDeadline = INF;

    /**
     * @param Closure(): ?WorkerServer $workers This master's worker transport
     * @param Closure(CommandReplyDTO): void $reply Delivery to the held local console
     */
    public function __construct(private readonly Closure $workers, private readonly Closure $reply)
    {
    }

    /**
     * @param CommandRequestDTO $request Held request, independently checked at this boundary
     * @param ?float $now Current seconds, injectable for deadline tests
     */
    public function request(CommandRequestDTO $request, ?float $now = null): void
    {
        if (!NonProductionGate::admitted()) {
            ($this->reply)(CommandReplyDTO::error(
                $request->correlationId,
                TestOnlyCommandOnProductionException::message(CliCommands::ORM_TEST_FORGET),
            ));
            return;
        }
        $process = $request->payload[OrmTestConstants::FIELD_PROCESS] ?? null;
        $workerId = $request->payload[OrmTestConstants::FIELD_WORKER_ID] ?? null;
        $daemon = $process === OrmTestConstants::PROCESS_DAEMON && count($request->payload) === 1;
        $worker = $process === OrmTestConstants::PROCESS_WORKER && count($request->payload) === 2
            && is_int($workerId) && $workerId !== 0;
        if (!$daemon && !$worker) {
            ($this->reply)(CommandReplyDTO::error($request->correlationId, OrmTestConstants::INVALID_TARGET));
            return;
        }

        try {
            if ($daemon) {
                if (Hilos::$db === null) {
                    ($this->reply)(CommandReplyDTO::error($request->correlationId, OrmTestConstants::NO_CONTEXT));
                    return;
                }
                if (Database::hasOpenTransactions()) {
                    ($this->reply)(CommandReplyDTO::error($request->correlationId, OrmTestConstants::OPEN_TRANSACTIONS));
                    return;
                }
                Hilos::$db->forgetLoadedDataForTest();
                ($this->reply)(CommandReplyDTO::ok($request->correlationId, [
                    OrmTestConstants::FIELD_PROCESS => OrmTestConstants::PROCESS_DAEMON,
                    OrmTestConstants::FIELD_WORKER_ID => null,
                ]));
                return;
            }
            $connection = ($this->workers)()?->sendTestOrmForget($workerId, new WorkerTestOrmForgetDTO($request->correlationId));
            if ($connection === null) {
                ($this->reply)(CommandReplyDTO::error($request->correlationId, "Worker {$workerId} is not connected"));
                return;
            }
            $deadline = ($now ?? microtime(true)) + CommandChannelWindows::AGENT_WAIT_SECONDS;
            $this->pending[$request->correlationId] = new TestOrmForgetRequest(
                $request->correlationId, $connection, $workerId, $deadline,
            );
            $this->nextDeadline = min($this->nextDeadline, $deadline);
        } catch (Throwable $failure) {
            ($this->reply)(CommandReplyDTO::error($request->correlationId, $failure->getMessage()));
        }
    }

    /**
     * Unknown, duplicate and foreign-connection frames leave waiting untouched.
     *
     * @param WorkerClient $worker Actual sender connection
     * @param WorkerTestOrmForgottenDTO $ack Parsed outcome
     * @param ?float $now Current seconds, injectable for deadline tests
     */
    public function accept(WorkerClient $worker, WorkerTestOrmForgottenDTO $ack, ?float $now = null): void
    {
        $request = $this->pending[$ack->correlationId] ?? null;
        if ($request === null || $request->worker !== $worker) {
            return;
        }
        if (($now ?? microtime(true)) >= $request->deadline) {
            $this->finish($request, "Worker {$request->workerId} did not confirm ORM forget in time");
            return;
        }
        if ($worker->shouldClose()) {
            $this->workerGone($worker);
            return;
        }
        $this->finish($request, $ack->error);
    }

    /** @param WorkerClient $worker Original connection that closed */
    public function workerGone(WorkerClient $worker): void
    {
        foreach ($this->pending as $request) {
            if ($request->worker === $worker) {
                $this->finish($request, "Worker {$request->workerId} disconnected before ORM forget completed");
            }
        }
    }

    /** @param string $correlationId Request whose caller stopped waiting */
    public function abandon(string $correlationId): void
    {
        unset($this->pending[$correlationId]);
        if ($this->pending === []) {
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
        foreach ($this->pending as $request) {
            if ($now >= $request->deadline) {
                $this->finish($request, "Worker {$request->workerId} did not confirm ORM forget in time");
            } else {
                $this->nextDeadline = min($this->nextDeadline, $request->deadline);
            }
        }
    }

    /**
     * @param TestOrmForgetRequest $request Wait to consume before replying
     * @param ?string $error Refusal, or null after successful local work
     */
    private function finish(TestOrmForgetRequest $request, ?string $error): void
    {
        $this->abandon($request->correlationId);
        ($this->reply)($error === null
            ? CommandReplyDTO::ok($request->correlationId, [
                OrmTestConstants::FIELD_PROCESS => OrmTestConstants::PROCESS_WORKER,
                OrmTestConstants::FIELD_WORKER_ID => $request->workerId,
            ])
            : CommandReplyDTO::error($request->correlationId, $error));
    }
}
