<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the first window a table declares (HIL-642).
 *
 * The size and the order of the first window moved from thirteen frontend controllers onto the
 * table definitions, because the window is now built when the page is subscribed and there is no
 * client in that moment to ask. What the tests pin is that a table which declares nothing still
 * has a first window, that a table which declares one is taken at its word, and that the order
 * it declares is held to the same vocabulary a client-chosen one is — no second way of checking
 * an order was introduced for the sake of the declared one.
 */
final class TableWindowDeclarationTest extends TestCase
{
    public function testATableThatDeclaresNothingCarriesTheDefaultWindow(): void
    {
        $table = new WindowDeclarationUnitTable();

        self::assertSame(TableConstants::DEFAULT_WINDOW_SIZE, $table->windowSize());
        self::assertNull($table->defaultSort());
    }

    public function testATableIsTakenAtItsWordAboutItsFirstWindow(): void
    {
        $order = TableSortOrderDTO::of(new TableSortDTO(WindowDeclarationUnitTable::FIELD_LABEL));
        $table = new WindowDeclarationUnitTable(windowSize: 50, defaultSort: $order);

        self::assertSame(50, $table->windowSize());
        self::assertSame($order, $table->defaultSort());
    }

    public function testADeclaredOrderReachesTheQueryCarryingItsColumn(): void
    {
        $table = new WindowDeclarationUnitTable(
            defaultSort: TableSortOrderDTO::of(
                new TableSortDTO(WindowDeclarationUnitTable::FIELD_LABEL, TableConstants::ORDER_DESC),
            ),
            sortableFields: [WindowDeclarationUnitTable::FIELD_LABEL => 'row_label'],
        );

        $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()));

        $component = $table->received?->sort?->last();
        self::assertNotNull($component);
        self::assertSame(WindowDeclarationUnitTable::FIELD_LABEL, $component->field);
        self::assertSame(TableConstants::ORDER_DESC, $component->direction);
        self::assertSame('row_label', $component->column);
    }

    public function testADeclaredFieldOutsideTheVocabularyCostsTheFirstWindowItsOrdering(): void
    {
        $table = new WindowDeclarationUnitTable(
            defaultSort: TableSortOrderDTO::of(new TableSortDTO(WindowDeclarationUnitTable::FIELD_CHANNEL)),
            sortableFields: [WindowDeclarationUnitTable::FIELD_LABEL => 'row_label'],
        );

        ob_start();
        $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()));
        $logged = (string) ob_get_clean();

        // A declaration is judged where every other order of this table is judged, so a table
        // that declares an order it does not serve gets what a window asking for one gets.
        self::assertNotNull($table->received);
        self::assertNull($table->received->sort);
        self::assertStringContainsString('Table sort order rejected', $logged);
    }

    public function testADeclaredCompositeOrderIsHeldAgainstTheOrdersTheTableOffered(): void
    {
        $offered = TableSortOrderDTO::of(
            new TableSortDTO(WindowDeclarationUnitTable::FIELD_CHANNEL, TableConstants::ORDER_DESC),
            new TableSortDTO(WindowDeclarationUnitTable::FIELD_LABEL, TableConstants::ORDER_DESC),
        );
        $table = new WindowDeclarationUnitTable(
            defaultSort: $offered,
            sortableFields: [
                WindowDeclarationUnitTable::FIELD_LABEL => 'row_label',
                WindowDeclarationUnitTable::FIELD_CHANNEL => 'row_channel',
            ],
            sortOrders: ['channelThenLabel' => $offered],
        );

        $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()));

        self::assertSame(['row_channel', 'row_label'], array_map(
            static fn(TableSortDTO $component): ?string => $component->column,
            $table->received?->sort?->components ?? [],
        ));
    }
}

final class WindowDeclarationUnitTable extends TableDefinition
{
    /** Row field the test table declares sortable. */
    public const string FIELD_LABEL = 'label';

    /** Row field a declared composite order runs on first. */
    public const string FIELD_CHANNEL = 'channel';

    /** Row field carrying the row key. */
    public const string FIELD_KEY = 'key';

    /** Query the concrete table was handed, or null while getPage() has not run. */
    public ?TableQueryDTO $received = null;

    /** Window size this table declares, or null to keep the inherited default. */
    private ?int $declaredWindowSize;

    /** Order this table declares for its first window. */
    private ?TableSortOrderDTO $declaredDefaultSort;

    /** @var array<string, string> Sortable fields this table declares */
    private array $declaredSortableFields;

    /** @var array<string, TableSortOrderDTO> Composite orders this table declares */
    private array $declaredSortOrders;

    /**
     * @param ?int $windowSize Window size the table declares, or null to inherit the default
     * @param ?TableSortOrderDTO $defaultSort Order the table declares for its first window
     * @param array<string, string> $sortableFields Sortable fields the table declares
     * @param array<string, TableSortOrderDTO> $sortOrders Composite orders the table declares
     */
    public function __construct(
        ?int $windowSize = null,
        ?TableSortOrderDTO $defaultSort = null,
        array $sortableFields = [],
        array $sortOrders = [],
    ) {
        $this->declaredWindowSize = $windowSize;
        $this->declaredDefaultSort = $defaultSort;
        $this->declaredSortableFields = $sortableFields;
        $this->declaredSortOrders = $sortOrders;

        parent::__construct();
    }

    /**
     * @return int Window size injected by the test, or the inherited default
     */
    public function windowSize(): int
    {
        return $this->declaredWindowSize ?? parent::windowSize();
    }

    /**
     * @return ?TableSortOrderDTO Order injected by the test, or none
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return $this->declaredDefaultSort;
    }

    /**
     * @return array<string, string> Sortable fields injected by the test
     */
    protected function sortableFields(): array
    {
        return $this->declaredSortableFields;
    }

    /**
     * @return array<string, TableSortOrderDTO> Composite orders injected by the test
     */
    protected function sortOrders(): array
    {
        return $this->declaredSortOrders;
    }

    /**
     * Records the query the gate produced and answers with one fixed row.
     *
     * @param TableQueryDTO $query Window query as the gate left it
     * @return TableSnapshotDTO One-row snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $this->received = $query;

        return new TableSnapshotDTO(
            rows: [[
                self::FIELD_KEY => 'a',
                self::FIELD_LABEL => 'Alpha',
                self::FIELD_CHANNEL => 'email',
            ]],
            totalCount: 1,
            limit: $query->limit,
        );
    }
}
