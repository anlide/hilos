<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonCronTableRow;
use PHPUnit\Framework\TestCase;

/** The cron table projects one node's ordered rules without deriving schedule state. */
final class HilosDaemonCronTableTest extends TestCase
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

    public function testNoPictureOrNodeFilterYieldsNoRows(): void
    {
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', new DaemonCronPicture(null, [$this->rule(null, 'cleanup')])));
        self::assertSame([], new HilosDaemonCronTable()->getPage(new TableQueryDTO())->rows);
        foreach ([null, '', 10, []] as $node) {
            self::assertSame([], new HilosDaemonCronTable()->getPage(new TableQueryDTO(
                filter: [HilosDaemonCronTable::FILTER_NODE => $node],
            ))->rows);
        }
        self::assertSame([], $this->rows('missing'));
    }

    public function testAbsentSlotOrCronYieldsNoRows(): void
    {
        $this->picture(new ClusterDaemonNodeView('n1', true, null));
        self::assertSame([], $this->rows('n1'));
        $this->picture($this->node('n1', null));
        self::assertSame([], $this->rows('n1'));
    }

    public function testRulesKeepPictureOrderAndAllFields(): void
    {
        $this->picture($this->node('n1', new DaemonCronPicture(null, [
            $this->rule(null, 'cleanup', last: null, next: 200),
            $this->rule('a', 'rotate', last: 100, next: 200),
            $this->rule('b', 'rotate', last: 150, next: 250),
        ])));

        $rows = $this->rows('n1');
        self::assertSame(['/cleanup', 'a/rotate', 'b/rotate'], array_map(static fn($row): string => $row->rowKey, $rows));
        self::assertSame([
            HilosDaemonCronTableRow::rowKey => '/cleanup',
            HilosDaemonCronTableRow::agentId => null,
            HilosDaemonCronTableRow::name => 'cleanup',
            HilosDaemonCronTableRow::expression => '* * * * *',
            HilosDaemonCronTableRow::lastRunAt => null,
            HilosDaemonCronTableRow::nextRunAt => 200,
            HilosDaemonCronTableRow::idleReason => null,
        ], $rows[0]->toArray());
        self::assertSame(['a', 'b'], [$rows[1]->agentId, $rows[2]->agentId]);
        self::assertSame(100, $rows[1]->lastRunAt);
        self::assertSame(250, $rows[2]->nextRunAt);
        self::assertSame(array_keys($rows[0]->toArray()), array_keys(new HilosDaemonCronTable()->wireFields()));
        self::assertNull(new HilosDaemonCronTable()->defaultSort());
    }

    public function testIdleReasonAffectsOnlyDaemonRulesAndSilentNodeKeepsItsRows(): void
    {
        $this->picture($this->node('n1', new DaemonCronPicture(DaemonCronPicture::IDLE_NOT_LEADER, [
            $this->rule(null, 'cleanup', last: 100, next: null),
            $this->rule('log', 'rotate', last: 110, next: 200),
        ]), online: false));

        $rows = $this->rows('n1');
        self::assertSame(DaemonCronPicture::IDLE_NOT_LEADER, $rows[0]->idleReason);
        self::assertNull($rows[0]->nextRunAt);
        self::assertNull($rows[1]->idleReason);
        self::assertSame(200, $rows[1]->nextRunAt);
    }

    public function testEncodedOwnerAndNamePairsDoNotCollide(): void
    {
        $this->picture($this->node('n1', new DaemonCronPicture(null, [
            $this->rule('a', 'b:c'),
            $this->rule('a:b', 'c'),
        ])));
        $rows = $this->rows('n1');
        self::assertSame(['a/b%3Ac', 'a%3Ab/c'], array_map(static fn($row): string => $row->rowKey, $rows));
        $query = new TableQueryDTO(filter: [HilosDaemonCronTable::FILTER_NODE => 'n1']);
        self::assertTrue(new HilosDaemonCronTable()->containsRow('a%3Ab/c', $query));
        self::assertFalse(new HilosDaemonCronTable()->containsRow('a/c', $query));
    }

    /** @return list<HilosDaemonCronTableRow> Rows in the selected node's first window */
    private function rows(string $node): array
    {
        /** @var list<HilosDaemonCronTableRow> $rows */
        $rows = new HilosDaemonCronTable()->getPage(new TableQueryDTO(
            filter: [HilosDaemonCronTable::FILTER_NODE => $node],
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
     * @param ?DaemonCronPicture $cron Cron section, if received
     * @param bool $online Whether the node still reports
     * @return ClusterDaemonNodeView Node view with its latest slot
     */
    private function node(string $id, ?DaemonCronPicture $cron, bool $online = true): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView(
            $id,
            $online,
            new ClusterDaemonNodeSlot($id, new NodeDaemonPicture($id, NodeRole::Master, 10, cron: $cron), 10),
        );
    }

    /**
     * @param ?string $agent Agent owner, or null for daemon
     * @param string $name Rule name
     * @param ?int $last Last firing
     * @param ?int $next Next firing
     * @return DaemonCronRulePicture Rule in the node picture
     */
    private function rule(?string $agent, string $name, ?int $last = null, ?int $next = null): DaemonCronRulePicture
    {
        return new DaemonCronRulePicture($agent, $name, '* * * * *', $last, $next);
    }
}
