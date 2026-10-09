<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTableRow;
use PHPUnit\Framework\TestCase;

/** The workers table projects one node's ordered process roster without deriving state. */
final class HilosDaemonWorkersTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        parent::tearDown();
    }

    public function testAbsentPictureNodeFilterSlotOrProcessesYieldNoRows(): void
    {
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', new DaemonProcessRoster([], [], 0)));
        self::assertSame([], new HilosDaemonWorkersTable()->getPage(new TableQueryDTO())->rows);
        foreach ([null, '', 10, []] as $node) {
            self::assertSame([], new HilosDaemonWorkersTable()->getPage(new TableQueryDTO(
                filter: [HilosDaemonWorkersTable::FILTER_NODE => $node],
            ))->rows);
        }
        self::assertSame([], $this->rows('missing'));
        self::assertSame([], $this->rows('n1'));
        $this->picture(new ClusterDaemonNodeView('n1', true, null));
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', null));
        self::assertSame([], $this->rows('n1'));
    }

    public function testWorkersKeepPictureOrderAndAllFieldsEvenOnSilentNode(): void
    {
        $roster = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 1234, 8192, [
                new DaemonAgentPicture('agent:a', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
                new DaemonAgentPicture('agent:b', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
            ]),
            new DaemonWorkerPicture(3, WorkerConstants::TYPE_MONOPOLISTIC, null, null, []),
        ], [], 0);
        $this->picture($this->node('n1', $roster, online: false));

        $rows = $this->rows('n1');
        self::assertSame(['regular:1', 'monopolistic:3'], array_map(static fn ($row): string => $row->rowKey, $rows));
        self::assertSame([
            HilosDaemonWorkersTableRow::rowKey => 'regular:1',
            HilosDaemonWorkersTableRow::index => 1,
            HilosDaemonWorkersTableRow::kind => 'regular',
            HilosDaemonWorkersTableRow::pid => 1234,
            HilosDaemonWorkersTableRow::memoryBytes => 8192,
            HilosDaemonWorkersTableRow::agentCount => 2,
            HilosDaemonWorkersTableRow::agentIds => ['agent:a', 'agent:b'],
            HilosDaemonWorkersTableRow::logStream => 'worker-regular-1.log',
        ], $rows[0]->toArray());
        self::assertSame('worker-monopolistic-3.log', $rows[1]->logStream);
        self::assertSame(0, $rows[1]->agentCount);
        self::assertSame([], $rows[1]->agentIds);
        self::assertNull($rows[1]->pid);
        self::assertNull($rows[1]->memoryBytes);
        self::assertSame(array_keys($rows[0]->toArray()), array_keys(new HilosDaemonWorkersTable()->wireFields()));
        self::assertNull(new HilosDaemonWorkersTable()->defaultSort());

        $query = new TableQueryDTO(filter: [HilosDaemonWorkersTable::FILTER_NODE => 'n1']);
        self::assertTrue(new HilosDaemonWorkersTable()->containsRow('regular:1', $query));
        self::assertFalse(new HilosDaemonWorkersTable()->containsRow('regular:3', $query));
        self::assertSame($rows[0]->toArray(), HilosDaemonWorkersTableRow::fromArray($rows[0]->toArray())->toArray());
    }

    public function testAgentIdsMustBeAStringList(): void
    {
        $row = new HilosDaemonWorkersTableRow('regular:1', 1, 'regular', null, null, 0, [], 'worker-regular-1.log');
        $data = $row->toArray();
        $data[HilosDaemonWorkersTableRow::agentIds] = [7];
        $this->expectException(InvalidFormatException::class);
        HilosDaemonWorkersTableRow::fromArray($data);
    }

    public function testNullAgentIdsAreRejected(): void
    {
        $row = new HilosDaemonWorkersTableRow('regular:1', 1, 'regular', null, null, 0, [], 'worker-regular-1.log');
        $data = $row->toArray();
        $data[HilosDaemonWorkersTableRow::agentIds] = null;
        $this->expectException(InvalidFormatException::class);
        HilosDaemonWorkersTableRow::fromArray($data);
    }

    /** @return list<HilosDaemonWorkersTableRow> Rows in the selected node's first window */
    private function rows(string $node): array
    {
        /** @var list<HilosDaemonWorkersTableRow> $rows */
        $rows = new HilosDaemonWorkersTable()->getPage(new TableQueryDTO(
            filter: [HilosDaemonWorkersTable::FILTER_NODE => $node],
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
