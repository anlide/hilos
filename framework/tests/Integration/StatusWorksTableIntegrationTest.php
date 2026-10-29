<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Testing\IntegrationTest;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use Hilos\Utils\Helpers\TimeHelper;

/** Real SQL windows, parent bridge rows and first page response inside the managed rollback root. */
#[IntegrationTest]
final class StatusWorksTableIntegrationTest extends StatusWorksTableIntegrationTestCase
{
    public function testFirstResponseCarriesCatalogSummaryAndExactInitialWorkWindow(): void
    {
        $now = TimeHelper::nowMs();
        $work = $this->env->asOwner(fn () => Hilos::$db->statusWorks->actions->create(
            'Upgrade', 'Public details', $now + 10000, $now + 20000, $now, ['service', 'mail']));
        $this->env->open();
        $frames = $this->env->drain();
        $pages = array_values(array_filter($frames, static fn ($frame): bool => $frame->signalName->getName() === 'page_response'));
        self::assertCount(1, $pages);
        $payload = $pages[0]->data->data->payload;
        self::assertSame(['components' => [['componentKey' => 'service', 'name' => 'Service'], ['componentKey' => 'mail', 'name' => 'Mail']],
            'upcomingCount' => 1, 'nextWork' => ['id' => $work->publicId, 'title' => 'Upgrade', 'description' => 'Public details',
                'componentKeys' => ['mail', 'service'], 'startsAt' => $now + 10000, 'endsAt' => $now + 20000]], $payload->data['statusWorks']);
        self::assertSame([], $payload->refusedWindows);
        $window = $payload->windows[HilosStatusWorksTable::TABLE];
        self::assertSame(1, $window['totalCount']);
        self::assertSame($work->publicId, $window['rows'][0]['slots']['work']['publicId']);
        self::assertSame(['mail', 'service'], $window['rows'][0]['slots']['work']['componentKeys']);
        self::assertSame(['id', 'publicId', 'title', 'description', 'componentKeys', 'startsAt', 'endsAt', 'version',
            'canceledAt', 'consumedAt', 'expiredAt', 'lastMailQueuedAt'], array_keys($window['rows'][0]['slots']['work']));
    }

    public function testAllSixIndexedOrdersAnchorsSearchFacetAndMembershipShareOneSet(): void
    {
        $table = new HilosStatusWorksTable();
        $rows = [];
        foreach (['Bravo', 'Alpha', 'Alpha'] as $index => $title) {
            $rows[] = $this->env->asOwner(fn () => Hilos::$db->statusWorks->actions->create(
                $title, $index === 1 ? 'needle' : null, 10000 + $index * 10000, 20000 + $index * 10000, 1,
                $index === 1 ? ['mail'] : ['service']));
        }
        foreach (['startsAt' => [0, 1, 2], 'endsAt' => [0, 1, 2], 'title' => [1, 2, 0]] as $field => $indices) {
            foreach (['asc', 'desc'] as $direction) {
                $order = $direction === 'asc' ? $indices : array_reverse($indices);
                $query = new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO($field, $direction)), limit: 2);
                $first = $table->getPage($query);
                self::assertSame(array_map(static fn (int $i): int => $rows[$i]->id, array_slice($order, 0, 2)),
                    array_map(static fn ($row): int => $row->id, $first->rows));
                $next = $table->getPage(new TableQueryDTO(sort: $query->sort, limit: 2, anchor: $first->lastAnchor));
                self::assertSame([$rows[$order[2]]->id], array_map(static fn ($row): int => $row->id, $next->rows));
                $back = $table->getPage(new TableQueryDTO(sort: $query->sort, limit: 2, anchor: $next->firstAnchor,
                    anchorDirection: TableAnchorDirection::Before));
                self::assertSame(array_map(static fn ($row): int => $row->id, $first->rows),
                    array_map(static fn ($row): int => $row->id, $back->rows));
            }
        }
        $set = new TableQueryDTO(search: 'needle', filter: ['componentKey' => 'mail']);
        self::assertSame(1, $table->getPage($set)->totalCount);
        self::assertTrue($table->containsRow($rows[1]->id, $set));
        self::assertFalse($table->containsRow($rows[0]->id, $set));
        $facets = $table->facetCounts($set, ['componentKey' => ['service', 'mail']]);
        self::assertSame(1, $facets['componentKey']['any']->count);
        self::assertSame(0, $facets['componentKey']['options']['service']->count);
        self::assertSame(1, $facets['componentKey']['options']['mail']->count);
        self::assertTrue($facets['componentKey']['options']['mail']->exact);
        foreach (['canceledAt' => 'cancel', 'consumedAt' => 'consume', 'expiredAt' => 'expire'] as $index => $action) {
            $target = array_shift($rows);
            $this->env->asOwner(fn () => $target->actions->$action($action === 'consume' ? $target->startsAt : 50000));
            self::assertFalse($table->containsRow($target->id, new TableQueryDTO()));
            self::assertNotNull($table->findRow($target->id)->toArray()[$index]);
        }
        self::assertSame(0, $table->getPage(new TableQueryDTO())->totalCount);
        $this->expectException(ValidationException::class);
        $table->getPage(new TableQueryDTO(filter: ['componentKey' => 'invented']));
    }
}

/** Schema and owner startup run before the rollback root; all case writes are rolled back before cleanup. */
abstract class StatusWorksTableIntegrationTestCase extends FrameworkRollbackIntegrationTestCase
{
    protected StatusWorksEnvironment $env;

    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        // The shared base's trust fixture references the people table this environment replaces.
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2)
            . '/backend/Database/Migration/Stub/create_hilos_second_factor_trust_down.sql'));
        $this->env = new StatusWorksEnvironment();
    }

    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if (isset($this->env)) {
                $this->env->close();
            }
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }
}
