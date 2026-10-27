<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
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

    public function testPictureDiffKeepsSilentRowsAndDistinguishesReportedEmpty(): void
    {
        $cron = new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120000)]);
        $this->picture($this->node('n1', $cron));
        $before = ClusterDaemonPictureMirror::picture();
        $this->picture($this->node('n1', $cron, false));
        $table = new HilosDaemonCronTable();
        $query = new TableQueryDTO(limit: 1, filter: ['node' => 'n1'], pageIndex: 5);
        self::assertSame([], $table->pictureMutations($before, $query));
        $before = ClusterDaemonPictureMirror::picture();
        $this->picture($this->node('n1', new DaemonCronPicture(null, [])));
        $mutations = $table->pictureMutations($before, $query);
        self::assertCount(1, $mutations);
        self::assertSame('delete', $mutations[0]->type->value);
        self::assertSame('/cleanup', $mutations[0]->rowKey);
        self::assertNull($mutations[0]->row);
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
            $this->rule(null, 'cleanup', last: null, next: 200000),
            $this->rule('a', 'rotate', last: 100000, next: 200000),
            $this->rule('b', 'rotate', last: 150000, next: 250000),
        ])));

        $rows = $this->rows('n1');
        self::assertSame(['/cleanup', 'a/rotate', 'b/rotate'], array_map(static fn($row): string => $row->rowKey, $rows));
        self::assertSame([
            HilosDaemonCronTableRow::rowKey => '/cleanup',
            HilosDaemonCronTableRow::agentId => null,
            HilosDaemonCronTableRow::name => 'cleanup',
            HilosDaemonCronTableRow::expression => '* * * * *',
            HilosDaemonCronTableRow::lastRunAt => null,
            HilosDaemonCronTableRow::nextRunAt => 200000,
            HilosDaemonCronTableRow::idleReason => null,
        ], $rows[0]->toArray());
        self::assertSame(['a', 'b'], [$rows[1]->agentId, $rows[2]->agentId]);
        self::assertSame(100000, $rows[1]->lastRunAt);
        self::assertSame(250000, $rows[2]->nextRunAt);
        self::assertSame(100000, new HilosDaemonCronTable()->browserRow($rows[1])['sources']['rule']['lastRunAt']);
        self::assertSame(200000, new HilosDaemonCronTable()->browserRow($rows[1])['sources']['rule']['nextRunAt']);
        self::assertSame(array_keys($rows[0]->toArray()), array_keys(new HilosDaemonCronTable()->wireFields()));
        self::assertNull(new HilosDaemonCronTable()->defaultSort());
    }

    public function testIdleReasonAffectsOnlyDaemonRulesAndSilentNodeKeepsItsRows(): void
    {
        $this->picture($this->node('n1', new DaemonCronPicture(DaemonCronPicture::IDLE_NOT_LEADER, [
            $this->rule(null, 'cleanup', last: 100000, next: null),
            $this->rule('log', 'rotate', last: 110000, next: 200000),
        ]), online: false));

        $rows = $this->rows('n1');
        self::assertSame(DaemonCronPicture::IDLE_NOT_LEADER, $rows[0]->idleReason);
        self::assertNull($rows[0]->nextRunAt);
        self::assertNull($rows[1]->idleReason);
        self::assertSame(200000, $rows[1]->nextRunAt);
    }

    public function testSearchReadsOnlyNameOrAgentIdAndClearingKeepsTheNode(): void
    {
        $this->picture(
            $this->node('n1', new DaemonCronPicture(null, [
                $this->rule(null, 'Cleanup Alpha'),
                $this->rule('a', 'sync beta'),
                $this->rule('alpha:2', 'rotate'),
                $this->rule('b', 'cleanup alpha'),
                $this->rule('c', 'encoded rule', last: 123456789, next: 987654321),
            ])),
            $this->node('n2', new DaemonCronPicture(null, [$this->rule('other', 'Alpha')])),
        );
        $table = new HilosDaemonCronTable();
        foreach ([
            '  ALPhA ' => ['/Cleanup%20Alpha', 'alpha%3A2/rotate', 'b/cleanup%20alpha'],
            'UP AL' => ['/Cleanup%20Alpha', 'b/cleanup%20alpha'],
            'PHA:2' => ['alpha%3A2/rotate'],
            'bet' => ['a/sync%20beta'],
            '*' => [],
            '123456789' => [],
            '987654321' => [],
            'c/encoded%20rule' => [],
            'encoded%20rule' => [],
            'missing' => [],
        ] as $search => $expected) {
            $search = (string) $search;
            $query = new TableQueryDTO(search: $search, filter: ['node' => 'n1']);
            $snapshot = $table->getPage($query);
            self::assertSame($expected, array_map(static fn($row): string => $row->rowKey, $snapshot->rows), $search);
            self::assertSame(count($expected), $snapshot->totalCount);
            foreach (['/Cleanup%20Alpha', 'alpha%3A2/rotate', 'b/cleanup%20alpha', 'other/Alpha'] as $key) {
                self::assertSame(in_array($key, $expected, true), $table->containsRow($key, $table->scopeSearch($query)), $key);
            }
        }
        foreach ([null, '', '   '] as $search) {
            $query = new TableQueryDTO(search: $search, filter: ['node' => 'n1']);
            self::assertSame(
                ['/Cleanup%20Alpha', 'a/sync%20beta', 'alpha%3A2/rotate', 'b/cleanup%20alpha', 'c/encoded%20rule'],
                array_map(static fn($row): string => $row->rowKey, $table->getPage($query)->rows),
            );
            self::assertFalse($table->containsRow('other/Alpha', $table->scopeSearch($query)));
        }
        self::assertSame(['other/Alpha'], array_map(static fn($row): string => $row->rowKey, $table->getPage(new TableQueryDTO(
            search: 'alpha', filter: ['node' => 'n2'],
        ))->rows));
    }

    public function testNameAndNextRunOrdersKeepKnownTimesAheadOfBothNullGroups(): void
    {
        $this->picture(
            $this->node('n1', new DaemonCronPicture(DaemonCronPicture::IDLE_NOT_LEADER, [
                $this->rule(null, 'zIdle'),
                $this->rule('a', 'rotate', next: 200000),
                $this->rule('b', 'Alpha', next: 100000),
                $this->rule('c', 'rotate', next: 200000),
                $this->rule('d', 'Never'),
                $this->rule('e', 'later', next: 1700000000000),
            ])),
            $this->node('n2', new DaemonCronPicture(null, [$this->rule('other', 'Alpha', next: 1)])),
        );
        $table = new HilosDaemonCronTable();
        foreach ([
            ['name', 'asc', ['b/Alpha', 'e/later', 'd/Never', 'a/rotate', 'c/rotate', '/zIdle']],
            ['name', 'desc', ['/zIdle', 'c/rotate', 'a/rotate', 'd/Never', 'e/later', 'b/Alpha']],
            ['nextRunAt', 'asc', ['b/Alpha', 'a/rotate', 'c/rotate', 'e/later', '/zIdle', 'd/Never']],
            ['nextRunAt', 'desc', ['e/later', 'c/rotate', 'a/rotate', 'b/Alpha', 'd/Never', '/zIdle']],
        ] as [$field, $direction, $expected]) {
            foreach ([null, array_keys($table->wireFields())] as $shownFields) {
                $query = new TableQueryDTO(
                    sort: TableSortOrderDTO::of(new TableSortDTO($field, $direction)),
                    filter: ['node' => 'n1'],
                    shownFields: $shownFields,
                );
                $snapshot = $table->getPage($query);
                self::assertSame($expected, array_map(static fn($row): string => $row->rowKey, $snapshot->rows));
                self::assertSame(6, $snapshot->totalCount);
                self::assertSame([$field, 'rowKey'], array_keys($snapshot->firstAnchor->values));
            }
        }
        $searched = $table->getPage(new TableQueryDTO(
            search: 'ROT',
            sort: TableSortOrderDTO::of(new TableSortDTO('nextRunAt', TableConstants::ORDER_DESC)),
            filter: ['node' => 'n1'],
            shownFields: array_keys($table->wireFields()),
        ));
        self::assertSame(['c/rotate', 'a/rotate'], array_map(static fn($row): string => $row->rowKey, $searched->rows));
        self::assertSame(
            ['/zIdle', 'a/rotate', 'b/Alpha', 'c/rotate', 'd/Never', 'e/later'],
            array_map(static fn($row): string => $row->rowKey, $this->rows('n1')),
        );
        self::assertNull($table->defaultSort());
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
