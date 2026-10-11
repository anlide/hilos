<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTableRow;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The placement table projects declared types against the current node rosters. */
final class HilosDaemonPlacementTableTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?ClusterContext $previousCluster;
    private string|false $previousClusterEnabled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        $this->previousCluster = Hilos::$cluster;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PlacementTableTestHilos::class);
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        Hilos::$cluster = $this->previousCluster;
        $this->previousClusterEnabled === false
            ? putenv('CLUSTER_ENABLED')
            : putenv('CLUSTER_ENABLED=' . $this->previousClusterEnabled);
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        parent::tearDown();
    }

    public function testEmptyPictureKeepsEveryDeclaredTypeInPlacementOrder(): void
    {
        $table = new HilosDaemonPlacementTable();
        $rows = $this->rows();
        self::assertSame(['leader', 'policy', 'x', 'empty', 'replica'], array_map(
            static fn (HilosDaemonPlacementTableRow $row): string => $row->agentType, $rows,
        ));
        self::assertSame([], $rows[0]->nodes);
        self::assertSame(DaemonAgentPicture::PLACEMENT_POLICY, $rows[1]->placement);
        self::assertTrue($rows[2]->idle);
        self::assertSame(DaemonAgentPicture::PLACEMENT_NODE, $rows[4]->placement);
        self::assertSame(count(PlacementTableTestHilos::AGENTS), $table->windowSize());
        self::assertNull($table->defaultSort());
        self::assertSame(array_keys($rows[0]->toArray()), array_keys($table->wireFields()));
        self::assertTrue($table->containsRow('leader', new TableQueryDTO()));
        self::assertFalse($table->containsRow('missing', new TableQueryDTO()));
        self::assertSame($rows[0]->toArray(), HilosDaemonPlacementTableRow::fromArray($rows[0]->toArray())->toArray());
        self::assertSame('type', array_key_first($table->browserRow($rows[0])['sources']));
    }

    public function testThreeNodesKeepNaturalInstancesAndUnknownCells(): void
    {
        $this->picture(
            $this->node('n3', $this->roster(['x:2', 'x:10', 'replica:1'])),
            $this->node('n1', $this->roster(['x:1', 'leader'])),
            $this->node('n2', $this->roster(['x:99']), online: false),
        );
        $rows = $this->byType();
        self::assertSame([
            ['nodeId' => 'n1', 'agentIds' => ['x:1']],
            ['nodeId' => 'n2', 'agentIds' => null],
            ['nodeId' => 'n3', 'agentIds' => ['x:2', 'x:10']],
        ], $rows['x']->nodes);
        self::assertSame([], $rows['empty']->nodes[0]['agentIds']);
        self::assertNull($rows['empty']->nodes[1]['agentIds']);
        self::assertSame([], $rows['empty']->nodes[2]['agentIds']);

        $this->picture($this->node('n1', null), new ClusterDaemonNodeView('n2', true, null));
        self::assertSame([null, null], array_column($this->byType()['x']->nodes, 'agentIds'));
    }

    public function testLeaderUnplacedVerdictSortsFirstAndNeverReadsFollowers(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $this->picture(
            $this->node('n2', $this->roster([], ['replica:lost']), leader: false),
            $this->node('n1', $this->roster([], ['x:10', 'x:2']), leader: true),
        );
        self::assertSame('x', $this->rows()[0]->agentType);
        self::assertSame(['x:10', 'x:2'], $this->byType()['x']->unplacedAgentIds);
        self::assertSame([], $this->byType()['replica']->unplacedAgentIds);

        $this->picture($this->node('n1', $this->roster([], ['x:2']), leader: false));
        self::assertSame([], $this->byType()['x']->unplacedAgentIds);
    }

    public function testStandaloneUsesItsOnlyNodeUnplacedVerdict(): void
    {
        $this->picture($this->node('solo', $this->roster([], ['policy:1'])));
        self::assertSame(['policy:1'], $this->byType()['policy']->unplacedAgentIds);
        self::assertSame('solo', $this->byType()['policy']->nodes[0]['nodeId']);
    }

    public function testSearchNarrowsInstancesUnlessTypeNameMatches(): void
    {
        $this->picture($this->node('n1', $this->roster(['x:1', 'x:2', 'x:10', 'replica:1'], ['policy:lost'])));
        $rows = $this->rows('X:1');
        self::assertSame(['x'], array_map(static fn ($row): string => $row->agentType, $rows));
        self::assertSame(['x:1', 'x:10'], $rows[0]->nodes[0]['agentIds']);
        $search = (new TableQueryDTO(search: 'x:1'))->withSearchScope([
            HilosDaemonPlacementTableRow::agentType => HilosDaemonPlacementTableRow::agentType,
            HilosDaemonPlacementTableRow::nodes => HilosDaemonPlacementTableRow::nodes,
            HilosDaemonPlacementTableRow::unplacedAgentIds => HilosDaemonPlacementTableRow::unplacedAgentIds,
        ]);
        self::assertTrue(new HilosDaemonPlacementTable()->containsRow('x', $search));
        self::assertFalse(new HilosDaemonPlacementTable()->containsRow('replica', $search));
        self::assertSame(['x:1', 'x:2', 'x:10'], $this->rows('x')[0]->nodes[0]['agentIds']);
        self::assertSame(['policy'], array_map(static fn ($row): string => $row->agentType, $this->rows('lost')));
        self::assertSame(['policy:lost'], $this->rows('lost')[0]->unplacedAgentIds);
    }

    public function testInvalidNodeListsAreRejected(): void
    {
        $data = new HilosDaemonPlacementTableRow('x', 'x', 'policy', false, [], []);
        $payload = $data->toArray();
        $payload[HilosDaemonPlacementTableRow::nodes] = [['nodeId' => 'n1', 'agentIds' => [7]]];
        $this->expectException(InvalidFormatException::class);
        HilosDaemonPlacementTableRow::fromArray($payload);
    }

    /** @return list<HilosDaemonPlacementTableRow> Current window */
    private function rows(?string $search = null): array
    {
        /** @var list<HilosDaemonPlacementTableRow> $rows */
        $rows = new HilosDaemonPlacementTable()->getPage(new TableQueryDTO(search: $search))->rows;
        return $rows;
    }

    /** @return array<string, HilosDaemonPlacementTableRow> Current rows by type */
    private function byType(): array
    {
        $rows = [];
        foreach ($this->rows() as $row) {
            $rows[$row->agentType] = $row;
        }
        return $rows;
    }

    /** @param ClusterDaemonNodeView ...$nodes Whole picture */
    private function picture(ClusterDaemonNodeView ...$nodes): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $nodes));
    }

    /** @param list<string> $agents Started ids; @param list<string> $unplaced Unplaced ids */
    private function roster(array $agents, array $unplaced = []): DaemonProcessRoster
    {
        sort($agents, SORT_STRING);
        $pictures = array_map(static fn (string $id): DaemonAgentPicture => new DaemonAgentPicture(
            $id,
            DaemonAgentPicture::scopeOf(PlacementTableTestHilos::AGENTS[explode(':', $id)[0]]),
            DaemonAgentPicture::placementOf(PlacementTableTestHilos::AGENTS[explode(':', $id)[0]]),
        ), $agents);
        sort($unplaced, SORT_STRING);
        return new DaemonProcessRoster([new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, 0, $pictures)], $unplaced, 0);
    }

    /** @return ClusterDaemonNodeView One node with an optional process section */
    private function node(string $id, ?DaemonProcessRoster $processes, bool $online = true, bool $leader = false): ClusterDaemonNodeView
    {
        $standing = new DaemonNodeStanding(0, 0, true, new DaemonConsensusPicture(
            $leader ? ConsensusRole::Leader : ConsensusRole::Follower, 1, $leader ? $id : null, 1, 3, 2,
        ));
        $picture = new NodeDaemonPicture($id, NodeRole::Master, 10, $processes, standing: $standing);
        return new ClusterDaemonNodeView($id, $online, new ClusterDaemonNodeSlot($id, $picture, 10));
    }
}

/** Declarations for matrix rows, including a type with no live instance. */
abstract class PlacementTableTestHilos extends Hilos
{
    public const array AGENTS = [
        'leader' => [],
        'policy' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        'x' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY, AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC],
        'empty' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'replica' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}
