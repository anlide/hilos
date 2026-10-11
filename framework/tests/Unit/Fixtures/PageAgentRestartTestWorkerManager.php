<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fixtures;

use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Worker\WorkerDaemonClient;
use ReflectionMethod;
use ReflectionProperty;
use LogicException;

/** Runs the real queue drain without worker startup or a child process. */
final class PageAgentRestartTestWorkerManager extends WorkerManager
{
    /** @param WorkerDaemonClient $client Transport the real queue drain writes through */
    public function __construct(WorkerDaemonClient $client)
    {
        new ReflectionProperty(WorkerManager::class, 'daemonClient')->setValue($this, $client);
    }

    /** Runs the production typed-control branch. */
    public function drain(): void
    {
        new ReflectionMethod(WorkerManager::class, 'dispatchQueuedSignalsToDaemon')->invoke($this);
    }

    /** @return SignalRouter No router construction is needed by the queue drain */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager No agent startup is needed by the queue drain
     * @throws LogicException On unexpected worker initialization
     */
    protected function createAgentManager(): AgentManager
    {
        throw new LogicException('Unexpected worker initialization');
    }
}
