<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Database\Database;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** One merged-history case that breaks loaded ORM memory and asks for a cold reset. */
#[IntegrationTest]
final class StatusHistoryOrmStateIntegrationTest extends StatusHistoryStorageTestCase implements BreaksOrmState
{
    /** Facets, membership and counts use SQL collation, escaped wildcards, saved component sets and the same filters. */
    public function testMergedMembershipFacetsSearchAndDeletedBoundaryAgree(): void
    {
        $this->incident(2, 1000);
        $this->incident(10, 2000, 'recovered_before_publication', null);
        $this->incident(11, 3000, 'canceled_publication', null, 'Other');
        $this->period(2, 1000);
        $this->period(10, 2000, keys: '["retired-api"]');
        $this->period(11, 3000, title: 'Other');
        $table = new HilosStatusHistoryTable();
        $query = new TableQueryDTO(search: 'SAVED 50%_!', filter: ['componentKey' => 'retired']);
        $window = $table->getPage($query);
        self::assertSame(['incident:10', 'maintenance:2', 'incident:2'], array_column($window->rows, 'id'));
        foreach (['incident:2', 'incident:10', 'maintenance:2'] as $key) {
            self::assertTrue($table->containsRow($key, $query));
        }
        foreach (['maintenance:10', 'incident:11', 'maintenance:11'] as $key) {
            self::assertFalse($table->containsRow($key, $query));
        }
        $facets = $table->facetCounts($query, ['componentKey' => ['retired', 'retired-api'],
            'outcome' => ['resolved', 'recovered_before_publication', 'canceled_publication', 'maintenance_completed'], 'from' => ['1970-01-01']]);
        self::assertSame(['componentKey', 'outcome'], array_keys($facets));
        self::assertSame(4, $facets['componentKey']['any']->count);
        self::assertSame(3, $facets['componentKey']['options']['retired']->count);
        self::assertSame(1, $facets['componentKey']['options']['retired-api']->count);
        self::assertSame([1, 1, 0, 1], array_map(static fn($count): int => $count->count, array_values($facets['outcome']['options'])));
        self::assertTrue($facets['outcome']['any']->exact);
        $shown = $table->getPage(new TableQueryDTO(search: 'no match', shownFields: ['outcome']));
        self::assertSame(6, $shown->totalCount);
        $anchor = new TableAnchorDTO(['startedAt' => 2000, 'kindRank' => 0, 'sourceId' => 10]);
        Database::sql('DELETE FROM `hilos_status_incident` WHERE `id` = 10');
        $after = $table->getPage(new TableQueryDTO(anchor: $anchor, limit: 2));
        self::assertSame(['maintenance:2', 'incident:2'], array_column($after->rows, 'id'));
        self::assertSame(3, $after->rowsBefore);
        self::assertSame(5, $after->totalCount);
    }
}
