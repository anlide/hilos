<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRegistry;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Daemon\WorkerInfo;
use Hilos\Core\Daemon\DaemonProcessRosterBuilder;
use Hilos\Core\Daemon\ProcessMetricsReader;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Large master rosters are assembled over passes and publish only whole results. */
final class DaemonProcessRosterBuilderTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ProcessRosterTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        parent::tearDown();
    }

    public function testThousandsOfAgentsRequireMultiplePassesAndSortPerWorker(): void
    {
        $server = new ProcessRosterTestWorkerServer();
        $server->workers = [new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [])];
        $agents = new ProcessRosterTestAgentManagerDaemon();
        for ($index = 999; $index >= 0; $index--) {
            $agents->ids['replica:' . sprintf('%04d', $index)] = null;
        }
        $builder = new DaemonProcessRosterBuilder(new ProcessMetricsReader());
        $builder->start($server, $agents, null, true);

        $builder->advance(100);
        self::assertNull($builder->result(), 'No partial frame may leave after the first pass');
        $passes = 1;
        while ($builder->result() === null && $passes < 50) {
            $builder->advance(100);
            $passes++;
        }

        self::assertGreaterThan(10, $passes);
        self::assertCount(1000, $builder->result()?->workers[0]->agents);
        self::assertSame('replica:0000', $builder->result()?->workers[0]->agents[0]->id);
        self::assertSame('replica:0999', $builder->result()?->workers[0]->agents[999]->id);
        self::assertSame([], $builder->result()?->unplacedAgentIds);
    }

    public function testLeaderListsBothUnplacedAndRefusedButFollowerDoesNotClaimAnEmptyList(): void
    {
        $server = new ProcessRosterTestWorkerServer();
        $agents = new ProcessRosterTestAgentManagerDaemon();
        $placement = new PlacementRegistry();
        $placement->put(new PlacementRecord('policy', 'z', 'n1', PlacementState::Refused));
        $placement->put(new PlacementRecord('policy', 'a', 'n1', PlacementState::Unplaced));
        $placement->put(new PlacementRecord('policy', 'running', 'n1', PlacementState::Started));
        $builder = new DaemonProcessRosterBuilder(new ProcessMetricsReader());

        $builder->start($server, $agents, $placement, true);
        $builder->advance(100);
        self::assertSame(['policy:a', 'policy:z'], $builder->result()?->unplacedAgentIds);

        $builder->start($server, $agents, $placement, false);
        $builder->advance(100);
        self::assertNull($builder->result()?->unplacedAgentIds);
    }

    public function testARebuildDiscardsAnUnfinishedFrame(): void
    {
        $server = new ProcessRosterTestWorkerServer();
        $agents = new ProcessRosterTestAgentManagerDaemon();
        $agents->ids = ['replica:old' => null];
        $builder = new DaemonProcessRosterBuilder(new ProcessMetricsReader());
        $builder->start($server, $agents, null, true);
        $builder->advance(1);
        self::assertNull($builder->result());

        $agents->ids = [];
        $builder->start($server, $agents, null, true);
        $builder->advance(100);
        self::assertSame([], $builder->result()?->workers);
    }

    public function testPendingAndUnhostedAgentsAreExcludedFromLiveWorkers(): void
    {
        $server = new ProcessRosterTestWorkerServer();
        $server->workers = [new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [])];
        $agents = new ProcessRosterTestAgentManagerDaemon();
        $agents->ids = ['replica:pending' => null, 'replica:orphan' => null, 'replica:started' => null];
        $agents->notStarted['replica:pending'] = true;
        $agents->workerIndexes['replica:orphan'] = 99;
        $builder = new DaemonProcessRosterBuilder(new ProcessMetricsReader());
        $builder->start($server, $agents, null, true);
        $builder->advance(100);

        self::assertSame(['replica:started'], array_map(
            static fn ($agent): string => $agent->id,
            $builder->result()?->workers[0]->agents ?? [],
        ));
    }
}

/** Worker source exposing only its typed roster to the builder. */
final class ProcessRosterTestWorkerServer extends WorkerServer
{
    /** @var list<DaemonWorkerPicture> */
    public array $workers = [];

    public function __construct()
    {
        // No sockets or child processes are needed by this reader test.
    }

    /** @return list<DaemonWorkerPicture> Synthetic live workers */
    public function liveWorkerPictures(): array
    {
        return $this->workers;
    }

    /**
     * @param ?int $now Ignored test clock
     * @return int No replacements in this test
     */
    public function workerRestarts24h(?int $now = null): int
    {
        return 0;
    }

    protected function onStart(): void
    {
    }
}

/** Agent source whose identities can be changed without a worker process. */
final class ProcessRosterTestAgentManagerDaemon extends AgentManagerDaemon
{
    /** @var array<string, null> */
    public array $ids = [];

    /** @var array<string, true> */
    public array $notStarted = [];

    /** @var array<string, int> */
    public array $workerIndexes = [];

    /** @return array<string, null> Synthetic agent map */
    public function getAgents(): array
    {
        return $this->ids;
    }

    /**
     * @param string $agentId Synthetic id
     * @return bool Every synthetic id has completed its start
     */
    public function isAgentStarted(string $agentId): bool
    {
        return array_key_exists($agentId, $this->ids) && !isset($this->notStarted[$agentId]);
    }

    /**
     * @param string $agentId Synthetic id
     * @return WorkerInfo All synthetic agents share the first regular worker
     */
    public function getAgentWorkerInfo(string $agentId): WorkerInfo
    {
        return new WorkerInfo($this->workerIndexes[$agentId] ?? 1, false);
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('This test reads only agent ids');
    }
}

/** Registry with node replicas and a policy-placed type. */
abstract class ProcessRosterTestHilos extends Hilos
{
    public const array AGENTS = [
        'replica' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'policy' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}
