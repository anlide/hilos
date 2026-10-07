<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Process;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Unexpected worker loss becomes one restart only after a replacement starts. */
final class WorkerServerProcessRosterTest extends TestCase
{
    public function testUnexpectedLossReplacementAndExpiry(): void
    {
        $server = new ProcessRosterWorkerServer();
        $server->seed(2, WorkerConstants::TYPE_REGULAR, 202);
        self::assertSame(1, $server->rosterRevision());
        self::assertSame(0, $server->workerRestarts24h());
        $server->lose(2, WorkerConstants::TYPE_REGULAR);
        $server->lose(2, WorkerConstants::TYPE_REGULAR);
        self::assertSame(2, $server->rosterRevision(), 'Status and error paths must not count one death twice');
        self::assertSame(0, $server->workerRestarts24h(), 'A loss without replacement is not a restart');

        $server->replacementStarted(2);
        self::assertSame(3, $server->rosterRevision());
        self::assertSame(1, $server->workerRestarts24h());
        $server->replacementStarted(2);
        self::assertSame(1, $server->workerRestarts24h(), 'The pending loss is consumed by the first start');

        $now = time();
        new ReflectionProperty(WorkerServer::class, 'workerRestartTimes')->setValue($server, [$now - 86401, $now - 10]);
        self::assertSame(1, $server->workerRestarts24h($now));
    }

    public function testShutdownLossIsNotCountedAndLiveRosterKeepsUnknownPidNullable(): void
    {
        $server = new ProcessRosterWorkerServer();
        $server->seed(3, WorkerConstants::TYPE_MONOPOLISTIC, null);
        $server->seed(1, WorkerConstants::TYPE_REGULAR, 101);
        self::assertSame([1, 3], array_map(static fn ($worker): int => $worker->index, $server->liveWorkerPictures()));
        self::assertNull($server->liveWorkerPictures()[1]->pid);

        $server->prepareStop();
        $server->lose(3, WorkerConstants::TYPE_MONOPOLISTIC);
        $server->replacementStarted(3);
        self::assertSame(0, $server->workerRestarts24h());
    }
}

/** Drives WorkerServer's real in-memory bookkeeping without launching children. */
final class ProcessRosterWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    /**
     * @param int $index Worker index
     * @param string $kind Worker kind
     * @param ?int $pid Fake operating-system pid
     */
    public function seed(int $index, string $kind, ?int $pid): void
    {
        $workers = new ReflectionProperty(WorkerServer::class, 'workers');
        $rows = $workers->getValue($this);
        $rows[$kind . ':' . $index] = [
            WorkerConstants::FIELD_WORKER_PROCESS => new ProcessRosterFakeProcess($pid),
            WorkerConstants::FIELD_WORKER_TYPE => $kind,
            WorkerConstants::FIELD_WORKER_INDEX => $index,
        ];
        $workers->setValue($this, $rows);
        $this->recordWorkerStarted($index);
    }

    /**
     * @param int $index Worker index
     * @param string $kind Worker kind
     */
    public function lose(int $index, string $kind): void
    {
        new ReflectionMethod(WorkerServer::class, 'removeWorker')->invoke($this, $kind . ':' . $index, $kind, $index);
    }

    /** @param int $index Worker index */
    public function replacementStarted(int $index): void
    {
        $this->recordWorkerStarted($index);
    }

    public function prepareStop(): void
    {
        $this->preparingShutdown = true;
    }

    protected function onStart(): void
    {
    }
}

/** A proc_open child represented only by the status the roster reads. */
final class ProcessRosterFakeProcess extends Process
{
    /** @param ?int $pid Fake OS pid */
    public function __construct(private readonly ?int $pid)
    {
    }

    /** @return array<string, mixed> Running fake child status */
    public function getStatus(): array
    {
        return [self::STATUS_RUNNING => true, 'pid' => $this->pid];
    }
}
