<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Socket\Worker\WorkerDTO;
use ReflectionMethod;

/** Drives the production ingress and reader lifetime in one worker without running its event loop. */
final class AccountStandingCardWorker extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
    }

    /** @param WorkerDTO $message Daemon frame delivered with the caller's execution context preserved */
    public function deliver(WorkerDTO $message): void
    {
        ExecutionContext::run(new ExecutionFrame(), fn () => $this->handleDaemonMessage($message));
    }

    /** @return ?DemoHilosAgent The actual index instance, absent while stopped */
    public function index(): ?DemoHilosAgent
    {
        return $this->agentManager->getAgent(HilosAgentType::HILOS_INDEX);
    }

    /** @param string $acceptKey Last scoped reader leaving this worker */
    public function releasePage(string $acceptKey): void
    {
        new ReflectionMethod(WorkerManager::class, 'releaseSourceInterest')->invoke($this, SourceConsumer::page($acceptKey));
    }

    /** @return SignalRouter The card fixture's real chat topology */
    protected function createSignalRouter(): SignalRouter
    {
        return new AccountStandingCardRouter();
    }

    /** @return AgentManager Factory that creates a fresh concrete index on each start */
    protected function createAgentManager(): AgentManager
    {
        return new AccountStandingCardAgentManager();
    }
}

/** Creates only the card's real index agent; lifecycle and interests belong to WorkerManager. */
final class AccountStandingCardAgentManager extends AgentManager
{
    /**
     * @param string $agentType Requested type
     * @param ?string $agentIndex Requested index
     * @return AgentInterface Fresh index agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        if ($agentType !== HilosAgentType::HILOS_INDEX || $agentIndex !== null) {
            throw new AgentCreationFailedException($agentType, $agentIndex);
        }

        return new DemoHilosAgent();
    }
}
