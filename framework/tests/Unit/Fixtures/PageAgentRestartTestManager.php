<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fixtures;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use ReflectionMethod;

/** Runs the master's real bridge and drain with no process startup or console required. */
final class PageAgentRestartTestManager extends DaemonManager
{
    /** @var list<CommandRequestDTO> */
    public array $requests = [];
    /** @var list<HandoverAnswerSignalData> */
    public array $answers = [];
    public ?PageAgentRestartTestConsole $console = null;
    public PageAgentRestartTestWorkerServer $worker;

    /**
     * @param bool $runRestart Whether to enter the existing restart mechanism after capture
     * @param bool $withConsole Whether to register a recording console channel
     */
    public function __construct(private readonly bool $runRestart = false, bool $withConsole = false)
    {
        parent::__construct();
        $this->worker = new PageAgentRestartTestWorkerServer();
        $this->registerServer($this->worker);
        if ($withConsole) {
            $this->console = new PageAgentRestartTestConsole();
            $this->registerServer($this->console);
        }
    }

    /** @param CommandRequestDTO $request Command handed by the real bridge to the existing restart entrance */
    public function requestAgentRestart(CommandRequestDTO $request): void
    {
        $this->requests[] = $request;
        if ($this->runRestart) {
            parent::requestAgentRestart($request);
        }
    }

    /**
     * @param string $agentType Return-address type
     * @param ?string $agentIndex Return-address index
     * @param string $signalName Outcome name
     * @param SignalDataInterface $data Typed outcome
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        if ($signalName === HilosSignalConstants::DAEMON_AGENT_RESTART_DONE && $data instanceof HandoverAnswerSignalData) {
            $this->answers[] = $data;
        }
    }

    /** Runs the same private reply dispatch the daemon's loop runs. */
    public function drain(): void
    {
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this);
    }

    /** @return SignalRouter Real command reply routing */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Empty roster without project factories */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PageAgentRestartTestAgents();
    }
}

final class PageAgentRestartTestAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Requested type
     * @param ?string $agentIndex Requested index
     * @return AgentDaemonInterface No factory is used by these transport tests
     * @throws AgentDaemonCreationFailedException If a test unexpectedly starts an agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('Unexpected test agent start');
    }
}

final class PageAgentRestartTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    protected function onStart(): void
    {
    }
}

final class PageAgentRestartTestConsole extends CommandServer
{
    /** @var list<CommandReplyDTO> */
    public array $replies = [];

    public function __construct()
    {
    }

    /**
     * @param string $correlationId Console correlation
     * @param CommandReplyDTO $reply Outcome written to the channel
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }

    protected function onStart(): void
    {
    }
}
