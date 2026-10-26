<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonProcessRosterBuilder;
use Hilos\Core\Daemon\ProcessMetricsReader;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Shared in-memory worker link; start frames, acknowledgements and process rosters stay production code. */
final class StatusComponentBootstrapTestSupport
{
    /**
     * @param ?StatusBootstrapTestAgentManager $manager Optional daemon-owned manager
     * @param int $workerIndex Regular worker identity
     * @return array{StatusBootstrapTestWorkerServer, StatusBootstrapTestAgentManager, StatusBootstrapTestWorkerClient} Linked fixture
     */
    public static function buildServer(?StatusBootstrapTestAgentManager $manager = null, int $workerIndex = 17): array
    {
        $manager ??= new StatusBootstrapTestAgentManager();
        // Worker env and log-directory setup are outside this in-memory lifecycle regression.
        $server = new ReflectionClass(StatusBootstrapTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);

        return [$server, $manager, $server->addWorker($workerIndex)];
    }

    /**
     * @param StatusBootstrapTestWorkerServer $server Fixture worker identities
     * @param StatusBootstrapTestAgentManager $manager Actual acknowledged bootstrap roster
     * @return DaemonProcessRoster Whole production roster, never a manually assigned healthy verdict
     */
    public static function roster(StatusBootstrapTestWorkerServer $server, StatusBootstrapTestAgentManager $manager): DaemonProcessRoster
    {
        $builder = new DaemonProcessRosterBuilder(new ProcessMetricsReader());
        $builder->start($server, $manager, null, true);
        $builder->advance(32);
        $roster = $builder->result();
        Assert::assertNotNull($roster, 'The one-worker fixture must finish within one bounded build.');

        return $roster;
    }
}

/** Exposes lifecycle steps while retaining the production start, stop and worker-selection paths. */
final class StatusBootstrapTestWorkerServer extends WorkerServer
{
    /**
     * @param int $index Worker connection index
     * @return StatusBootstrapTestWorkerClient Worker whose real write buffer captures the frames
     */
    public function addWorker(int $index): StatusBootstrapTestWorkerClient
    {
        $client = new ReflectionClass(StatusBootstrapTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($index);
        new ReflectionProperty(WorkerClient::class, 'agentManager')->setValue(
            $client,
            new ReflectionProperty(WorkerServer::class, 'agentManager')->getValue($this),
        );
        $this->clients[] = $client;

        return $client;
    }

    /** @return list<DaemonWorkerPicture> Socket fixture identities without launching OS processes */
    public function liveWorkerPictures(): array
    {
        return array_map(
            static fn(StatusBootstrapTestWorkerClient $worker): DaemonWorkerPicture =>
                new DaemonWorkerPicture($worker->getWorkerIndex(), 'regular', null, null, []),
            $this->clients,
        );
    }

    /** Drops the worker link before the manager consumes its lost roster. */
    public function disconnectWorker(): void
    {
        $this->clients = [];
    }

    /** @param string $agentId Agent removed through the production stop path */
    public function stopPublic(string $agentId): void
    {
        $this->stopAgent($agentId);
    }

    /** Runs the base protected-mode lift. */
    public function liftPublic(): void
    {
        $this->onProtectedModeLifted();
    }

    /** Advances the real stopped-roster replay by one master pass. */
    public function rosterStepPublic(): void
    {
        $this->advanceProtectedModeRoster();
    }

    /** No sockets or worker processes are launched by this fixture. */
    protected function onStart(): void
    {
    }
}

/** Reads real serialized frames without replacing sendAgentStart() or send(). */
final class StatusBootstrapTestWorkerClient extends WorkerClient
{
    /** @return list<WorkerDTO> Frames in the production write buffer */
    public function frames(): array
    {
        if ($this->writeBuffer === '') {
            return [];
        }

        return array_map(WorkerDTO::factoryWorkerDTO(...), explode("\n", rtrim($this->writeBuffer, "\n")));
    }

    /** @return list<string> Agent ids in serialized agent_start frames */
    public function startIds(): array
    {
        return array_values(array_map(
            static fn(AgentStartDTO $frame): string => $frame->agentId,
            array_filter($this->frames(), static fn(WorkerDTO $frame): bool => $frame instanceof AgentStartDTO),
        ));
    }

    /**
     * @param WorkerDTO $report Worker acknowledgement decoded through the production handler
     */
    public function report(WorkerDTO $report): void
    {
        new ReflectionMethod(WorkerClient::class, 'processMessage')->invoke($this, $report->toJson());
    }
}

/** Keeps real manager records and allows one factory failure for containment coverage. */
final class StatusBootstrapTestAgentManager extends AgentManagerDaemon
{
    /** @var list<string> Types requested from the factory */
    public array $requestedTypes = [];

    /** @var ?string Type whose factory refuses to create an agent */
    public ?string $failingType = null;

    /**
     * @param string $agentType Requested agent type
     * @param ?string $agentIndex Requested instance index
     * @return AgentDaemonInterface Regular-worker proxy
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        $this->requestedTypes[] = $agentType;
        if ($agentType === $this->failingType) {
            throw new AgentDaemonCreationFailedException($agentType, $agentIndex);
        }

        return new StatusBootstrapTestAgentDaemon($agentType);
    }
}

/** Minimal regular-worker daemon proxy for the fixture's permanent singleton types. */
final class StatusBootstrapTestAgentDaemon extends AbstractAgentDaemon
{
    /**
     * @param string $type Registry type of this proxy
     */
    public function __construct(private readonly string $type)
    {
    }

    /** @return string Registry type of this proxy */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return bool False so the real worker selection picks the regular fixture worker */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * @param AgentMessageDTOInterface $message Unused user message
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
    }
}

