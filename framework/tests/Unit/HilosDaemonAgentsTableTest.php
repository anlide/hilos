<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTableRow;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The agents table projects live instances and their class declarations. */
final class HilosDaemonAgentsTableTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, DaemonAgentsTableTestHilos::class);
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        parent::tearDown();
    }

    public function testMissingPictureFilterSlotOrProcessesYieldsNoRows(): void
    {
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', new DaemonProcessRoster([], [], 0)));
        self::assertSame([], new HilosDaemonAgentsTable()->getPage(new TableQueryDTO())->rows);
        foreach ([null, '', 10, []] as $node) {
            self::assertSame([], new HilosDaemonAgentsTable()->getPage(new TableQueryDTO(
                filter: [HilosDaemonAgentsTable::FILTER_NODE => $node],
            ))->rows);
        }
        self::assertSame([], $this->rows('missing'));
        self::assertSame([], $this->rows('n1'));
        $this->picture(new ClusterDaemonNodeView('n1', true, null));
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', null));
        self::assertSame([], $this->rows('n1'));
    }

    public function testRowsCarryWorkerPlacementOwnershipAndLogInNaturalOrder(): void
    {
        $roster = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 123, 8192, [
                new DaemonAgentPicture('x:10', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
            ]),
            new DaemonWorkerPicture(3, WorkerConstants::TYPE_MONOPOLISTIC, null, null, [
                new DaemonAgentPicture('unknown:1', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
                new DaemonAgentPicture('x:2', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_LEADER),
            ]),
        ], [], 0);
        $this->picture($this->node('n1', $roster, online: false));

        $rows = $this->rows('n1');
        self::assertSame(['unknown:1', 'x:2', 'x:10'], array_map(static fn ($row): string => $row->rowKey, $rows));
        self::assertSame([], $rows[0]->ownsRt);
        self::assertSame([], $rows[0]->ownsDb);
        self::assertFalse($rows[0]->idle);
        self::assertSame(3, $rows[1]->workerIndex);
        self::assertSame(WorkerConstants::TYPE_MONOPOLISTIC, $rows[1]->workerKind);
        self::assertSame(DaemonAgentPicture::PLACEMENT_LEADER, $rows[1]->placement);
        self::assertSame('agent-x_2.log', $rows[1]->logStream);
        self::assertTrue($rows[1]->idle);
        self::assertSame([
            ['collection' => 'rt_a', 'width' => 'whole'],
            ['collection' => 'rt_b', 'width' => 'rows'],
            ['collection' => 'rt_c', 'width' => 'set'],
        ], $rows[1]->ownsRt);
        self::assertSame([
            ['collection' => 'db_a', 'width' => 'whole'],
            ['collection' => 'db_b', 'width' => 'rows'],
            ['collection' => 'db_c', 'width' => 'set'],
        ], $rows[1]->ownsDb);
        self::assertSame(1, $rows[2]->workerIndex);
        self::assertSame(25, new HilosDaemonAgentsTable()->windowSize());
        self::assertSame(array_keys($rows[1]->toArray()), array_keys(new HilosDaemonAgentsTable()->wireFields()));
        self::assertNull(new HilosDaemonAgentsTable()->defaultSort());
        self::assertSame($rows[1]->toArray(), HilosDaemonAgentsTableRow::fromArray($rows[1]->toArray())->toArray());
    }

    public function testSearchAndContainsRowUseOnlyAgentId(): void
    {
        $this->picture($this->node('n1', new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [
                new DaemonAgentPicture('x:1', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
                new DaemonAgentPicture('x:10', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
                new DaemonAgentPicture('x:2', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
            ]),
        ], [], 0)));
        $query = new TableQueryDTO(search: 'x:1', filter: [HilosDaemonAgentsTable::FILTER_NODE => 'n1']);
        $table = new HilosDaemonAgentsTable();
        self::assertSame(['x:1', 'x:10'], array_map(static fn ($row): string => $row->rowKey, $table->getPage($query)->rows));
        self::assertTrue($table->containsRow('x:1', $table->scopeSearch($query)));
        self::assertFalse($table->containsRow('x:2', $table->scopeSearch($query)));
    }

    public function testMalformedOwnershipWidthIsRejected(): void
    {
        $row = new HilosDaemonAgentsTableRow('x:1', 'x:1', 1, 'regular', 'node', false, [], [], 'agent-x_1.log');
        $data = $row->toArray();
        $data[HilosDaemonAgentsTableRow::ownsRt] = [['collection' => 'rt_a', 'width' => 'other']];
        $this->expectException(InvalidFormatException::class);
        HilosDaemonAgentsTableRow::fromArray($data);
    }

    public function testRegistryEntryWithoutAWorkerClassHasNoDeclaration(): void
    {
        $this->picture($this->node('n1', new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [
                new DaemonAgentPicture('absent:1', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
            ]),
        ], [], 0)));

        $row = $this->rows('n1')[0];
        self::assertFalse($row->idle);
        self::assertSame([], $row->ownsRt);
        self::assertSame([], $row->ownsDb);
    }

    /** @return list<HilosDaemonAgentsTableRow> Rows in the selected node's first window */
    private function rows(string $node): array
    {
        /** @var list<HilosDaemonAgentsTableRow> $rows */
        $rows = new HilosDaemonAgentsTable()->getPage(new TableQueryDTO(
            filter: [HilosDaemonAgentsTable::FILTER_NODE => $node],
        ))->rows;

        return $rows;
    }

    /** @param ClusterDaemonNodeView ...$nodes Node views in a whole picture */
    private function picture(ClusterDaemonNodeView ...$nodes): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $nodes));
    }

    /**
     * @param string $id Node id
     * @param ?DaemonProcessRoster $processes Process section, if received
     * @param bool $online Whether the node still reports
     * @return ClusterDaemonNodeView Node view with its latest slot
     */
    private function node(string $id, ?DaemonProcessRoster $processes, bool $online = true): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView(
            $id,
            $online,
            new ClusterDaemonNodeSlot($id, new NodeDaemonPicture($id, NodeRole::Master, 10, processes: $processes), 10),
        );
    }
}

/** Application registry for the table's declaration projection. */
abstract class DaemonAgentsTableTestHilos extends Hilos
{
    public const array AGENTS = [
        'x' => [
            AgentRegistryKey::WORKER => DaemonAgentsTableTestAgent::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::IDLE_TIMEOUT => 60,
        ],
        'absent' => [AgentRegistryKey::WORKER => 'NoSuchDaemonAgent'],
    ];
}

/** Agent declaring all three widths in both halves. */
final class DaemonAgentsTableTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'x';

    public const array OWNS_RT = ['rt_a' => [TruthSourceOperation::Update]];
    public const array OWNS_RT_ROWS = ['rt_b' => [TruthSourceOperation::Update]];
    public const array OWNS_RT_SET = ['rt_c' => [TruthSourceOperation::Update]];
    public const array OWNS_DB = ['db_a' => [TruthSourceOperation::Update]];
    public const array OWNS_DB_ROWS = ['db_b' => [TruthSourceOperation::Update]];
    public const array OWNS_DB_SET = ['db_c' => [TruthSourceOperation::Update]];

    /** This fixture has no running lifecycle. */
    public function onStop(): void
    {
    }
}
