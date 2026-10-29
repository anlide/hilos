<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonRestartingAgents;
use Hilos\DaemonSection\DaemonRestartingAgent;
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
    private const string SILENT_REASON = 'This node is not responding, so its agents cannot be restarted right now.';
    private const string RESTARTING_REASON = 'This agent is already restarting.';
    private const string UNAVAILABLE_REASON = 'Node data is unavailable; wait for it to refresh.';

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

    public function testPictureDiffUsesSubjectRatherThanSearchOrPaginationForDeletes(): void
    {
        $this->picture($this->node('n1', new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 100, 200, [
                new DaemonAgentPicture('known:1', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
            ]),
        ], [], 0)));
        $before = ClusterDaemonPictureMirror::picture();
        $this->picture($this->node('n1', new DaemonProcessRoster([
            new DaemonWorkerPicture(2, WorkerConstants::TYPE_REGULAR, 100, 200, [
                new DaemonAgentPicture('known:1', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
            ]),
        ], [], 0)));
        $table = new HilosDaemonAgentsTable();
        $query = new TableQueryDTO(search: 'absent', limit: 1, filter: ['node' => 'n1'], pageIndex: 10);
        $mutations = $table->pictureMutations($before, $query);
        self::assertCount(1, $mutations);
        self::assertSame('update', $mutations[0]->type->value);
        self::assertSame('known:1', $mutations[0]->rowKey);
        self::assertSame(2, $mutations[0]->row->toArray()['workerIndex']);
        self::assertFalse($table->containsRow('known:1', $table->scopeSearch($query)));
        self::assertSame([], $table->pictureMutations($before, new TableQueryDTO(filter: ['node' => 'other'])));
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
        $row = new HilosDaemonAgentsTableRow(
            'x:1', 'x:1', false, 1, 'regular', 'node', false, [], [], 'agent-x_1.log', null, self::UNAVAILABLE_REASON,
        );
        $data = $row->toArray();
        $data[HilosDaemonAgentsTableRow::ownsRt] = [['collection' => 'rt_a', 'width' => 'other']];
        $this->expectException(InvalidFormatException::class);
        HilosDaemonAgentsTableRow::fromArray($data);
    }

    public function testLeaderMarkKeepsAnAbsentInstanceOnItsOriginalNodeUntilMoveCompletes(): void
    {
        $worker = new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 123, 8192, [
            new DaemonAgentPicture('x:2', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
        ]);
        $mark = new DaemonRestartingAgent('x:2', 'n1');
        $this->picture($this->node('n1', new DaemonProcessRoster([$worker], [], 0)));
        self::assertFalse($this->rows('n1')[0]->restarting);

        $this->picture(
            $this->node('n1', new DaemonProcessRoster([$worker], [], 0)),
            $this->node('leader', new DaemonProcessRoster([], [], 0, [$mark])),
        );
        self::assertTrue($this->rows('n1')[0]->restarting);
        self::assertSame(1, $this->rows('n1')[0]->workerIndex);

        $this->picture(
            $this->node('n1', new DaemonProcessRoster([], [], 0)),
            $this->node('leader', new DaemonProcessRoster([], [], 0, [$mark])),
        );
        $row = $this->rows('n1')[0];
        self::assertTrue($row->restarting);
        self::assertNull($row->workerIndex);
        self::assertNull($row->workerKind);
        self::assertSame(DaemonAgentPicture::PLACEMENT_POLICY, $row->placement);
        self::assertTrue($row->idle);
        self::assertCount(3, $row->ownsRt);
        self::assertCount(3, $row->ownsDb);
        self::assertSame('agent-x_2.log', $row->logStream);
        self::assertSame($row->toArray(), HilosDaemonAgentsTableRow::fromArray($row->toArray())->toArray());
        self::assertSame([], $this->rows('leader'));
        self::assertSame([], $this->rows('another'));
        self::assertSame([], new HilosDaemonAgentsTable()->getPage(new TableQueryDTO())->rows);

        $table = new HilosDaemonAgentsTable();
        $query = new TableQueryDTO(search: 'x:2', filter: [HilosDaemonAgentsTable::FILTER_NODE => 'n1']);
        self::assertSame('x:2', $table->getPage($query)->rows[0]->getRowKey());
        self::assertTrue($table->containsRow('x:2', $table->scopeSearch($query)));

        $this->picture(
            $this->node('n1', new DaemonProcessRoster([], [], 0)),
            $this->node('leader', new DaemonProcessRoster([], [], 0)),
            $this->node('n2', new DaemonProcessRoster([$worker], [], 0)),
        );
        self::assertSame([], $this->rows('n1'));
        self::assertFalse($this->rows('n2')[0]->restarting);
    }

    public function testRestartProjectionDeduplicatesAllSlotsAndNaturallySortsOnlyTheSelectedNodesMarks(): void
    {
        $this->picture(
            $this->node('leader', new DaemonProcessRoster([], [], 0, [
                new DaemonRestartingAgent('x:10', 'n1'),
                new DaemonRestartingAgent('x:2', 'n1'),
                new DaemonRestartingAgent('x:3', 'n2'),
            ])),
            $this->node('n1', new DaemonProcessRoster([], [], 0, [new DaemonRestartingAgent('x:2', 'n1')])),
        );
        self::assertSame(['x:2', 'x:10'], array_map(
            static fn (DaemonRestartingAgent $agent): string => $agent->id,
            DaemonRestartingAgents::forNode(ClusterDaemonPictureMirror::picture(), 'n1'),
        ));
        self::assertSame(['x:2', 'x:10'], array_map(static fn ($row): string => $row->rowKey, $this->rows('n1')));

        $this->picture($this->node('n1', new DaemonProcessRoster([], [], 0)));
        self::assertSame([], $this->rows('n1'));
    }

    public function testRestartFailureClearsTheMarkOnTheStillLiveInstance(): void
    {
        $worker = new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [
            new DaemonAgentPicture('x:1', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
        ]);
        $this->picture($this->node('n1', new DaemonProcessRoster([$worker], [], 0, [new DaemonRestartingAgent('x:1', 'n1')])));
        self::assertTrue($this->rows('n1')[0]->restarting);
        $this->picture($this->node('n1', new DaemonProcessRoster([$worker], [], 0)));
        self::assertFalse($this->rows('n1')[0]->restarting);
    }

    public function testRequiredNullableWorkerFieldsRejectAbsenceAndWrongTypes(): void
    {
        $row = new HilosDaemonAgentsTableRow(
            'x:1', 'x:1', true, null, null, 'node', false, [], [], 'agent-x_1.log', null, self::UNAVAILABLE_REASON,
        );
        foreach ([
            HilosDaemonAgentsTableRow::workerIndex,
            HilosDaemonAgentsTableRow::workerKind,
            HilosDaemonAgentsTableRow::restartDisabledReason,
        ] as $field) {
            foreach (['absent', 'wrong'] as $invalid) {
                $data = $row->toArray();
                if ($invalid === 'absent') {
                    unset($data[$field]);
                } else {
                    $data[$field] = false;
                }
                try {
                    HilosDaemonAgentsTableRow::fromArray($data);
                    self::fail('A required nullable worker field was accepted without its proper value');
                } catch (InvalidFormatException $exception) {
                    self::assertStringContainsString($field, $exception->getMessage());
                }
            }
        }
    }

    public function testSilentNodeExplainsRestartOnEveryRowAndItsReturnClearsTheReason(): void
    {
        $roster = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [
                new DaemonAgentPicture('x:1', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
                new DaemonAgentPicture('x:2', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
            ]),
        ], [], 0);
        $query = new TableQueryDTO(filter: [HilosDaemonAgentsTable::FILTER_NODE => 'n1']);
        $table = new HilosDaemonAgentsTable();
        $this->picture($this->node('n1', $roster));
        self::assertSame([[null, self::UNAVAILABLE_REASON], [null, self::UNAVAILABLE_REASON]], $this->reasons('n1'));

        $before = ClusterDaemonPictureMirror::picture();
        $this->picture($this->node('n1', $roster, online: false));
        self::assertSame(
            [[self::SILENT_REASON, self::UNAVAILABLE_REASON], [self::SILENT_REASON, self::UNAVAILABLE_REASON]],
            $this->reasons('n1'),
        );
        $mutations = $table->pictureMutations($before, $query);
        self::assertSame(['update', 'update'], array_map(static fn ($mutation): string => $mutation->type->value, $mutations));
        self::assertSame(self::SILENT_REASON, $mutations[0]->row->toArray()[HilosDaemonAgentsTableRow::restartDisabledReason]);

        $before = ClusterDaemonPictureMirror::picture();
        $this->picture($this->node('n1', $roster));
        $mutations = $table->pictureMutations($before, $query);
        self::assertCount(2, $mutations);
        self::assertNull($mutations[1]->row->toArray()[HilosDaemonAgentsTableRow::restartDisabledReason]);
        self::assertSame(self::UNAVAILABLE_REASON, $mutations[1]->row->toArray()[HilosDaemonAgentsTableRow::restartUnavailableReason]);
    }

    public function testRestartMarkExplainsItsRowsUnderTheSilentNodeAndLeavesWithTheMark(): void
    {
        $worker = new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, null, null, [
            new DaemonAgentPicture('x:1', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
            new DaemonAgentPicture('x:2', DaemonAgentPicture::SCOPE_NODE, DaemonAgentPicture::PLACEMENT_NODE),
        ]);
        $marks = [new DaemonRestartingAgent('x:1', 'n1'), new DaemonRestartingAgent('x:3', 'n1')];
        $query = new TableQueryDTO(filter: [HilosDaemonAgentsTable::FILTER_NODE => 'n1']);
        $table = new HilosDaemonAgentsTable();
        $this->picture($this->node('n1', new DaemonProcessRoster([$worker], [], 0)));
        $before = ClusterDaemonPictureMirror::picture();
        $this->picture($this->node('n1', new DaemonProcessRoster([$worker], [], 0, $marks)));
        self::assertSame([
            [self::RESTARTING_REASON, self::UNAVAILABLE_REASON],
            [null, self::UNAVAILABLE_REASON],
            [self::RESTARTING_REASON, self::UNAVAILABLE_REASON],
        ], $this->reasons('n1'));
        $mutations = $table->pictureMutations($before, $query);
        self::assertSame([['update', 'x:1'], ['create', 'x:3']], array_map(
            static fn ($mutation): array => [$mutation->type->value, $mutation->rowKey],
            $mutations,
        ));
        self::assertSame(self::RESTARTING_REASON, $mutations[1]->row->toArray()[HilosDaemonAgentsTableRow::restartDisabledReason]);

        $this->picture($this->node('n1', new DaemonProcessRoster([$worker], [], 0, $marks), online: false));
        self::assertSame(
            [self::SILENT_REASON, self::SILENT_REASON, self::SILENT_REASON],
            array_column($this->reasons('n1'), 0),
        );

        $before = ClusterDaemonPictureMirror::picture();
        $this->picture($this->node('n1', new DaemonProcessRoster([$worker], [], 0)));
        self::assertSame([[null, self::UNAVAILABLE_REASON], [null, self::UNAVAILABLE_REASON]], $this->reasons('n1'));
        self::assertSame([['update', 'x:1'], ['update', 'x:2'], ['delete', 'x:3']], array_map(
            static fn ($mutation): array => [$mutation->type->value, $mutation->rowKey],
            $table->pictureMutations($before, $query),
        ));
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

    /** @return list<array{?string, string}> Disabled and unavailable restart sentences of the node's rows */
    private function reasons(string $node): array
    {
        return array_map(
            static fn (HilosDaemonAgentsTableRow $row): array => [$row->restartDisabledReason, $row->restartUnavailableReason],
            $this->rows($node),
        );
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
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
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
