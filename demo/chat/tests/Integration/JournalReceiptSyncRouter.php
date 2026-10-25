<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;

/** Keeps emitted row facts beside the normal queue so handover helpers cannot discard them. */
class JournalReceiptSyncRouter extends SignalRouter
{
    /** @var list<SignalDTO> Actual ORM frames released after commit */
    private array $rowFacts = [];

    /**
     * @param SignalSourceInterface $signalSource Original transport source
     * @param SignalTypeInterface $signalType Existing signal type
     * @param SignalNameInterface $signalName Existing signal name
     * @param SignalDataInterface $signalData Original ORM payload
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
        if ($signalData instanceof DbSyncSignalDataInterface) {
            $this->rowFacts[] = new SignalDTO($signalSource, $signalType, $signalName, $signalData);
        }
    }

    /**
     * Delivers every captured row fact without reproducing the journal receiver's filter.
     *
     * @param ?WorkerManager $worker Fixture worker, or a local journal-only dispatcher
     */
    public function deliverTo(?WorkerManager $worker = null): void
    {
        $agentId = ExecutionContext::currentAgentId();
        try {
            $worker ??= new JournalReceiptSyncWorker();
            $facts = $this->rowFacts;
            $this->rowFacts = [];
            $worker->handleDaemonMessage(new AgentStartDTO(DemoHilosChangeLogAgent::AGENT_TYPE));
            foreach ($facts as $fact) {
                $worker->handleDaemonMessage(new DaemonAgentMessageDTO(DemoHilosChangeLogAgent::AGENT_TYPE, $fact));
            }
        } finally {
            ExecutionContext::setCurrentAgentId($agentId);
        }
    }
    /** @return class-string<Hilos> The same topology facade as the project's final router */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}

/** A local dispatcher for fixtures that exercise scopes without a business worker. */
final class JournalReceiptSyncWorker extends WorkerManager
{
    /** Installs an inert daemon link; every delivered fact still uses handleDaemonMessage(). */
    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClient = new JournalReceiptSyncClient();
    }

    /** @return SignalRouter Current fixture queue */
    protected function createSignalRouter(): SignalRouter
    {
        return Hilos::$sr;
    }

    /** @return AgentManager Factory for the actual project journal agent */
    protected function createAgentManager(): AgentManager
    {
        return new JournalReceiptSyncAgentManager();
    }
}

final class JournalReceiptSyncAgentManager extends AgentManager
{
    /**
     * @param string $agentType Journal agent type
     * @param ?string $agentIndex Unused singleton index
     * @return AgentInterface Actual project journal receiver
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new DemoHilosChangeLogAgent();
    }
}

final class JournalReceiptSyncClient extends WorkerDaemonClient
{
    /** @param WorkerDTO|array $data Outgoing worker acknowledgement, unused on this fixture link */
    public function send(WorkerDTO|array $data): void
    {
    }

    /** @return bool Local fixture link is always available */
    public function isConnected(): bool
    {
        return true;
    }
}
