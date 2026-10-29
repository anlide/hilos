<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;
use Hilos\ProtectedMode\Exception\ProtectedModeException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTableRow;
use Hilos\Tests\Unit\Fixtures\MaintenanceNodesTestCase;

/** Projection windows and diffs use the existing RT source, without an independent snapshot. */
final class HilosMaintenanceNodesTableTest extends MaintenanceNodesTestCase
{
    public function testWindowSortAndRowEnvelope(): void
    {
        $this->publish([self::node('z'), self::node('a', 'verifying', true)]);
        $table = new HilosMaintenanceNodesTable();
        $snapshot = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()));
        self::assertSame(25, $table->windowSize());
        self::assertSame(['a', 'z'], array_map(static fn($row) => $row->nodeId, $snapshot->rows));
        $row = $snapshot->rows[0];
        self::assertSame(self::node('a', 'verifying', true), $row->toArray());
        self::assertSame($row->toArray(), HilosMaintenanceNodesTableRow::fromArray($row->toArray())->toArray());
        self::assertSame(['rowKey' => 'a', 'sources' => ['node' => $row->toArray()]], $table->browserRow($row));
        self::assertArrayNotHasKey('id', $table->browserRow($row)[BrowserPageSignalData::sources]['node']);
        $reverse = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO('nodeId', 'DESC'))));
        self::assertSame(['z', 'a'], array_map(static fn($row) => $row->nodeId, $reverse->rows));
        self::assertSame(array_keys($row->toArray()), array_keys($table->wireFields()));
        foreach ($table->wireFields() as $field) {
            self::assertFalse($field->isColumn());
            self::assertFalse($field->isSetting());
            self::assertNull($field->fields);
        }
        self::assertNull($table->getItemActionsClass());
        $this->expectException(TableActionsNotConfiguredException::class);
        $table->actions;
    }

    public function testTwentyFiveRowsAreWindowedAndUnsupportedSortIsDropped(): void
    {
        $nodes = [];
        for ($i = 29; $i >= 0; --$i) {
            $nodes[] = self::node(sprintf('node-%02d', $i));
        }
        $this->publish($nodes);
        $table = new HilosMaintenanceNodesTable();
        $window = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: 25));
        self::assertCount(25, $window->rows);
        self::assertSame(30, $window->totalCount);
        self::assertSame('node-00', $window->rows[0]->nodeId);
        self::assertSame('node-24', $window->rows[24]->nodeId);
        $tail = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: 25, pageIndex: 1));
        self::assertCount(5, $tail->rows);
        foreach (['phase', 'quiesced', 'isLeader', 'isInitiator', 'reportState'] as $field) {
            $snapshot = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO($field))));
            self::assertSame('node-29', $snapshot->rows[0]->nodeId);
        }
    }

    public function testSearchIsUnsupported(): void
    {
        $this->publish([]);
        $this->expectException(TableSearchNotSupportedException::class);
        (new HilosMaintenanceNodesTable())->getPage(new TableQueryDTO(search: 'node'));
    }

    public function testMissingIsRefusedButPublishedEmptyIsAuthoritative(): void
    {
        $table = new HilosMaintenanceNodesTable();
        try {
            $table->getFullSnapshot();
            self::fail('Unpublished projection was served as an empty set');
        } catch (ProtectedModeException) {
            self::assertNull(Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes);
        }
        $this->publish([]);
        self::assertSame(0, $table->getFullSnapshot()->totalCount);
        Hilos::$rt = null;
        $this->expectException(ProtectedModeException::class);
        $table->getFullSnapshot();
    }

    public function testStandaloneViewportQueryIsNotFound(): void
    {
        $this->publish([]);
        putenv('CLUSTER_ENABLED=false');
        $this->expectException(PageResourceNotFoundException::class);
        (new HilosMaintenanceNodesTable())->getFullSnapshot();
    }

    public function testDiffCreatesUpdatesDeletesAndDoesNotConfuseUnrelatedFacts(): void
    {
        $table = new HilosMaintenanceNodesTable();
        $before = [self::node('a'), self::node('deleted'), self::node('unchanged')];
        $after = [self::node('a', 'active', true), self::node('created'), self::node('unchanged')];
        $this->publish($after);
        $change = SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::RT_ITEM,
            ['maintenanceNodes' => $after], previous: ['maintenanceNodes' => $before]);
        $mutations = $table->buildMutationsForSourceEvent($change);
        self::assertSame([TableMutationType::Update, TableMutationType::Create, TableMutationType::Delete],
            array_column($mutations, 'type'));
        self::assertSame(['a', 'created', 'deleted'], array_column($mutations, 'rowKey'));
        self::assertSame(self::node('a', 'active', true), $mutations[0]->row->toArray());
        self::assertNull($mutations[2]->row);
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::rtUpdated(
            ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::RT_ITEM, ['phase' => 'active'])));
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::rtUpdated(
            ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::RT_ITEM, ['maintenanceNodes' => $after],
            previous: ['maintenanceNodes' => $after])));
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            ProtectedModeRuntime::RT_ITEM, 'x', ['maintenanceNodes' => $after])));
        $this->publish(null);
        $this->expectException(ProtectedModeException::class);
        $table->buildMutationsForSourceEvent(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::RT_ITEM,
            ['maintenanceNodes' => null], previous: ['maintenanceNodes' => $after]));
    }
}
