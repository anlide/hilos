<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the order a table window runs in (HIL-789).
 *
 * The order is a sequence, so the tests are about the sequence surviving: it survives the wire
 * in both directions, it survives a gate replacing its components with resolved ones, and its
 * last component stays reachable because that is the one a tie-breaker continues.
 */
final class TableSortOrderDTOTest extends TestCase
{
    public function testAnOrderRidesTheWireAsTheListOfItsComponents(): void
    {
        $order = TableSortOrderDTO::of(
            new TableSortDTO('channel', TableConstants::ORDER_DESC, 'nd.channel'),
            new TableSortDTO('createdAt', TableConstants::ORDER_DESC, 'nd.created_at'),
        );

        // The column is developer-owned SQL and stays off the wire, exactly as it does for
        // one component on its own.
        self::assertSame([
            ['field' => 'channel', 'direction' => TableConstants::ORDER_DESC],
            ['field' => 'createdAt', 'direction' => TableConstants::ORDER_DESC],
        ], $order->toArray());
    }

    public function testTheWireListIsReadBackInTheSequenceItWasSentIn(): void
    {
        $order = TableSortOrderDTO::fromWire([
            ['field' => 'channel', 'direction' => TableConstants::ORDER_DESC],
            ['field' => 'createdAt', 'direction' => TableConstants::ORDER_ASC],
        ]);

        self::assertNotNull($order);
        self::assertSame(['channel', 'createdAt'], array_map(
            static fn(TableSortDTO $component): string => $component->field,
            $order->components,
        ));
        self::assertSame(
            [TableConstants::ORDER_DESC, TableConstants::ORDER_ASC],
            array_map(static fn(TableSortDTO $component): string => $component->direction, $order->components),
        );
    }

    public function testAnEmptyListIsNoOrderRatherThanAnOrderOverNothing(): void
    {
        self::assertNull(TableSortOrderDTO::fromWire([]));
    }

    public function testSomethingThatIsNoListIsNoOrder(): void
    {
        // The frame before this leaf carried one `{field, direction}` object; a client still
        // sending that names an order this side cannot read, not a broken frame.
        self::assertNull(TableSortOrderDTO::fromWire(['field' => 'channel']));
        self::assertNull(TableSortOrderDTO::fromWire('channel'));
        self::assertNull(TableSortOrderDTO::fromWire(null));
    }

    public function testOneUnreadableComponentDropsTheWholeOrder(): void
    {
        // Half an order is not an order: running the rest of it would serve a window
        // ordered some other way than the one asked for.
        self::assertNull(TableSortOrderDTO::fromWire([
            ['field' => 'channel', 'direction' => TableConstants::ORDER_DESC],
            ['direction' => TableConstants::ORDER_ASC],
        ]));
    }

    public function testTheLastComponentIsTheOneATieBreakerContinues(): void
    {
        $order = TableSortOrderDTO::of(
            new TableSortDTO('channel', TableConstants::ORDER_ASC),
            new TableSortDTO('createdAt', TableConstants::ORDER_DESC),
        );

        self::assertSame('createdAt', $order->last()->field);
        self::assertSame(TableConstants::ORDER_DESC, $order->last()->direction);
    }

    public function testAnOrderOfOneComponentIsItsOwnLast(): void
    {
        $order = TableSortOrderDTO::of(new TableSortDTO('channel', TableConstants::ORDER_DESC));

        self::assertSame('channel', $order->last()->field);
    }

    public function testResolvedComponentsReplaceTheOrdersOwnOneForOne(): void
    {
        $order = TableSortOrderDTO::of(
            new TableSortDTO('channel'),
            new TableSortDTO('createdAt'),
        );

        $resolved = $order->withComponents(array_map(
            static fn(TableSortDTO $component): TableSortDTO => $component->withColumn('nd.' . $component->field),
            $order->components,
        ));

        self::assertSame(['nd.channel', 'nd.createdAt'], array_map(
            static fn(TableSortDTO $component): ?string => $component->column,
            $resolved->components,
        ));
        // The order it came from is untouched: a gate hands on a new order rather than
        // writing into the window's own.
        self::assertNull($order->components[0]->column);
    }
}
