<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableFacetCountsSignalData;
use Hilos\Core\Table\DTO\TableFacetsDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client frame of counts beside a table's filter options (HIL-240).
 */
final class TableFacetCountsSignalDataTest extends TestCase
{
    /**
     * @throws HilosException When the frame does not restore from its own JSON
     */
    public function testRoundTripPreservesEveryCountAndTheWordOnIt(): void
    {
        $restored = TableFacetCountsSignalData::fromJson(new TableFacetCountsSignalData(
            'hilos_notification_deliveries',
            'deliveries',
            new TableFacetsDTO([
                'status' => [
                    TableConstants::FACET_KEY_ANY => new TableFacetCountDTO(TableConstants::COUNT_CEILING, false),
                    TableConstants::FACET_KEY_OPTIONS => [
                        'failed' => new TableFacetCountDTO(88, true),
                        'sent' => new TableFacetCountDTO(0, true),
                    ],
                ],
            ]),
        )->toJson());

        self::assertSame('hilos_notification_deliveries', $restored->page);
        self::assertSame('deliveries', $restored->tableKey);
        $status = $restored->facets->filters['status'];
        self::assertSame(TableConstants::COUNT_CEILING, $status[TableConstants::FACET_KEY_ANY]->count);
        self::assertFalse($status[TableConstants::FACET_KEY_ANY]->exact);
        self::assertSame(88, $status[TableConstants::FACET_KEY_OPTIONS]['failed']->count);
        self::assertSame(0, $status[TableConstants::FACET_KEY_OPTIONS]['sent']->count);
        self::assertTrue($status[TableConstants::FACET_KEY_OPTIONS]['sent']->exact);
    }

    /**
     * Options whose values read as numbers are keyed 0, 1, 2 inside PHP, and must still leave as a map:
     * as a JSON list they would match no option on the client.
     */
    public function testOptionsKeyedByNumbersTravelAsAMapAndNotAsAList(): void
    {
        $json = new TableFacetCountsSignalData('page', 'table', new TableFacetsDTO([
            'priority' => [
                TableConstants::FACET_KEY_ANY => new TableFacetCountDTO(3, true),
                TableConstants::FACET_KEY_OPTIONS => [0 => new TableFacetCountDTO(1, true), 1 => new TableFacetCountDTO(2, true)],
            ],
        ]))->toJson();

        self::assertStringContainsString('"options":{"0":{"count":1,"exact":true},"1":{"count":2,"exact":true}}', $json);
    }

    /**
     * A frame whose filters all fell away still carries a map, not an empty list.
     */
    public function testAnEmptySetOfFiltersTravelsAsAMap(): void
    {
        $json = new TableFacetCountsSignalData('page', 'table', new TableFacetsDTO([]))->toJson();

        self::assertStringContainsString('"facets":{}', $json);
    }

    public function testFromArrayRefusesAFilterWithoutItsAnyCount(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableConstants::FACET_KEY_ANY);

        TableFacetCountsSignalData::fromArray([
            TableFacetCountsSignalData::page => 'page',
            TableFacetCountsSignalData::tableKey => 'table',
            TableFacetCountsSignalData::facets => [
                'status' => [TableConstants::FACET_KEY_OPTIONS => []],
            ],
        ]);
    }

    public function testFromArrayRefusesACountWithoutTheWordOnIt(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableConstants::FACET_KEY_EXACT);

        TableFacetCountsSignalData::fromArray([
            TableFacetCountsSignalData::page => 'page',
            TableFacetCountsSignalData::tableKey => 'table',
            TableFacetCountsSignalData::facets => [
                'status' => [
                    TableConstants::FACET_KEY_ANY => [TableConstants::FACET_KEY_COUNT => 3],
                    TableConstants::FACET_KEY_OPTIONS => [],
                ],
            ],
        ]);
    }
}
