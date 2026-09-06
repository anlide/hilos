<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use ArrayAccess;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TableOffsetSetNotSupportedException;
use Hilos\Core\Table\Exception\TableOffsetUnsetNotSupportedException;
use Hilos\Core\Table\Exception\TablePropertyNotFoundException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Item\TableItem;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSortWhitelist;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use Throwable;

/**
 * Base definition for one registered table.
 *
 * Table definitions are stateless: each full snapshot pulls fresh data through
 * the table query. ArrayAccess exposes item-level actions such as
 * `$table->bots[$id]->actions->delete()`.
 *
 * @implements ArrayAccess<string|int, TableItem>
 */
abstract class TableDefinition implements ArrayAccess
{
    /** Browser table config declared by data-bearing table definitions. */
    public const array BROWSER = [];

    /** Log context key: the table whose rows do not carry the key their row class names. */
    private const string LOG_KEY_CONTEXT = 'context';

    /** Log context key: the tie-breaker field that was looked for and not found. */
    private const string LOG_KEY_FIELD = 'field';

    /** @var ?TableActions Lazy-loaded table-level actions instance */
    private ?TableActions $_actions = null;

    /** @var ?class-string<TableActions> Table actions class for create-like operations */
    private ?string $_actionsClass = null;

    /** @var ?class-string<TableItemActions> Item actions class for update/delete-like operations */
    private ?string $_itemActionsClass = null;

    /** @var class-string<AbstractTableRow> Row class used by this table */
    private string $_rowClass = GenericTableRow::class;

    /**
     * Creates the table definition and applies subclass configuration.
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Configures row and actions classes for subclasses.
     *
     * Called from the constructor after the base table state is initialized.
     */
    protected function init(): void
    {
    }

    /**
     * Registers the table-level actions class.
     *
     * @param class-string<TableActions> $class Table actions class name
     */
    protected function setActionsClass(string $class): void
    {
        $this->_actionsClass = $class;
    }

    /**
     * Registers the item-level actions class.
     *
     * @param class-string<TableItemActions> $class Item actions class name
     */
    protected function setItemActionsClass(string $class): void
    {
        $this->_itemActionsClass = $class;
    }

    /**
     * Registers the row class used by this table.
     *
     * @param class-string<AbstractTableRow> $class Table row class name
     */
    protected function setRowClass(string $class): void
    {
        $this->_rowClass = $class;
    }

    /**
     * Returns the row class used by this table.
     *
     * @return class-string<AbstractTableRow> Table row class name
     */
    public function getRowClass(): string
    {
        return $this->_rowClass;
    }

    /**
     * Builds one typed row object from an array payload.
     *
     * @param array<string, mixed> $row Row payload
     * @return AbstractTableRow Typed row object
     */
    public function makeRow(array $row): AbstractTableRow
    {
        $rowClass = $this->getRowClass();

        return $rowClass::fromArray($row);
    }

    /**
     * Builds typed row objects for each raw or already-typed payload.
     *
     * @param list<AbstractTableRow|array<string, mixed>> $rows Typed row objects or raw row payloads
     * @return list<AbstractTableRow> Typed row objects
     */
    public function makeRows(array $rows): array
    {
        return array_map(
            fn(AbstractTableRow|array $row): AbstractTableRow => $row instanceof AbstractTableRow ? $row : $this->makeRow($row),
            $rows,
        );
    }

    /**
     * Returns the registered item actions class, or null if not configured.
     *
     * @return ?class-string<TableItemActions> Item actions class or null
     */
    public function getItemActionsClass(): ?string
    {
        return $this->_itemActionsClass;
    }

    // ── Stateless query ──────────────────────────────────────────────────

    /**
     * Builds a table row mutation for one source change this table reacts to.
     *
     * Concrete tables decide whether the change affects their row state and
     * which DB/RT collections they observe. A table may react to one or more DB
     * sources, one or more RT sources, or any combination; the change kind and
     * source key are carried by the SourceChange DTO.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Mutation to fan out, or null when the table is unaffected
     * @throws Throwable Whatever the concrete table's row build raises
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Creates a row mutation DTO for source-change fan-out.
     *
     * @param TableMutationType $type Mutation type
     * @param string|int $rowKey Affected table row key
     * @param ?AbstractTableRow $row Row payload for create/update mutations
     * @param bool $live Whether the change must apply at once instead of waiting for Apply
     * @return TableRowMutationDTO Row mutation payload
     */
    protected function mutation(
        TableMutationType $type,
        string|int $rowKey,
        ?AbstractTableRow $row = null,
        bool $live = false,
    ): TableRowMutationDTO {
        return new TableRowMutationDTO($type, $rowKey, $row, $live);
    }

    /**
     * Declares this table's sort vocabulary: which fields it serves, and what each one orders by.
     *
     * The map does two things at once, and both of them are naming. It is the vocabulary every
     * order of this table is written in — a field outside it reaches no column anywhere — and it
     * is the declaration of the single-column orders themselves: a field named here is offered
     * in both directions, which is what a click on a column header asks for. Orders of more than
     * one column are declared separately, by {@see sortOrders()}, because an index lies under
     * each of those and under a click on a header there is only the one column.
     *
     * The map is `wire row-field name => column`; the keys are what the browser sends, the
     * values are developer code. How far a value may go depends on who runs the query: a table
     * assembling its own SQL may qualify it with its own alias (`nd.created_at`), while a table
     * whose rows come from the ORM must name a bare column of its entity — the ORM checks the
     * name against `Entity::_columns` again on its way to the query, and quotes it as one
     * identifier, so a qualified name there loses the sort rather than ordering by it.
     *
     * A table that declares nothing sorts as it always has — its rows are then ordered in PHP,
     * where a field name is an array key and nothing is built out of it — while a table that
     * declares a map has every one of its query paths held to it.
     *
     * @return array<string, string> Allowed sort fields mapped to their columns; empty by default
     */
    protected function sortableFields(): array
    {
        return [];
    }

    /**
     * Declares the orders of more than one column this table serves, each under a key of its own.
     *
     * A composite order is offered, never assembled: the reader picks one of these and cannot
     * put an arbitrary pair of columns together. The reason is that an order is served by an
     * index only when the index matches it in both the sequence of its columns and their
     * directions — any two columns out of eight give dozens of combinations, and an uncovered
     * one means the database sorts the whole filtered set on every show of the window. So
     * declaring an order here is a promise that an index lies under it, and the declaration and
     * its index travel as one change; the rules and the refusals are in
     * `docs/agents/frontend/table-sort-orders.md`.
     *
     * Every component names a field of {@see sortableFields()}, and every component of one order
     * runs the same way: a declaration that mixes directions or names a field outside the map is
     * passed over as though it were not written, because neither can be honoured by an index
     * this side can vouch for. The primary key is not part of a declaration — the query boundary
     * settles the order with it.
     *
     * The key is the order's own slug, which is what the frontend builds the
     * `hilos-table-order-<orderKey>` selector out of; it stays on this side of the wire, the
     * chosen order itself being what travels.
     *
     * @return array<string, TableSortOrderDTO> Order key => order it stands for; empty by default
     */
    protected function sortOrders(): array
    {
        return [];
    }

    /**
     * Loads table data for the given table query.
     *
     * Each concrete table owns its row source and may combine DB, runtime,
     * config, SQL aggregates, or any other data needed for its row shape.
     *
     * @param TableQueryDTO $query Query parameters
     * @return TableSnapshotDTO Snapshot with raw or typed rows
     * @throws HilosException When the concrete table cannot read its row source
     */
    abstract protected function query(TableQueryDTO $query): TableSnapshotDTO;

    /**
     * Queries a DB collection with the standard table search, sort, and pagination behavior.
     *
     * This helper is intended for simple tables whose rows are direct frontend
     * rows of a single DbCollection. Tables with joined, calculated, or
     * runtime-enriched rows should implement query() directly.
     *
     * @param DbCollection $collection Db collection used as the row source
     * @param TableQueryDTO $query Query parameters
     * @return TableSnapshotDTO Snapshot with raw rows
     * @throws DatabaseException When query execution fails
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When the object type does not match the collection
     */
    protected function queryDbCollection(DbCollection $collection, TableQueryDTO $query): TableSnapshotDTO
    {
        $objectCollection = $collection->getObjectCollection();

        if ($objectCollection === null || $objectCollection->isAllLoaded()) {
            $rows = $collection->toArray(idAsIndex: false, toFrontend: true);
            return $this->filterInMemory($rows, $query);
        }

        $result = $collection->queryPage($query);

        return new TableSnapshotDTO(
            rows: $result[TableConstants::RESULT_KEY_ROWS],
            totalCount: $result[TableConstants::RESULT_KEY_TOTAL_COUNT],
            limit: $query->limit,
            firstAnchor: $result[TableConstants::RESULT_KEY_FIRST_ANCHOR],
            lastAnchor: $result[TableConstants::RESULT_KEY_LAST_ANCHOR],
        );
    }

    /**
     * Filters, sorts and paginates rows this table already holds in memory.
     *
     * This is the seam that hands the in-memory filter the field the tie-breaker reads, taken
     * from the row class this table registered — the one place that knows both. Rows whose
     * payload does not carry that field cannot be told apart by the tie-breaker, so the window
     * says so once rather than once per row: an in-memory set runs to thousands of them.
     *
     * @param list<array<string, mixed>> $rows All rows the table holds
     * @param TableQueryDTO $query Query parameters
     * @return TableSnapshotDTO Filtered/sorted/paginated snapshot
     */
    protected function filterInMemory(array $rows, TableQueryDTO $query): TableSnapshotDTO
    {
        $rowClass = $this->getRowClass();
        $keyField = $rowClass::keyField();

        if ($query->sort !== null && !array_all($rows, static fn(array $row): bool => array_key_exists($keyField, $row))) {
            Logger::warning('Table tie-breaker field missing', [
                self::LOG_KEY_CONTEXT => static::class,
                self::LOG_KEY_FIELD => $keyField,
            ]);
        }

        return InMemoryTableFilter::apply($rows, $query, $keyField);
    }

    /**
     * Loads a complete table snapshot — the empty-query case of getPage().
     *
     * @return TableSnapshotDTO Full snapshot with typed rows and metadata
     * @throws HilosException When the concrete table cannot read its row source
     */
    public function getFullSnapshot(): TableSnapshotDTO
    {
        return $this->getPage(new TableQueryDTO());
    }

    /**
     * Loads one window of the table for the given query.
     *
     * Runs the concrete table query and wraps the result rows as typed row
     * objects; getFullSnapshot() is the empty-query case. The window's search,
     * sort, size and address — an anchor or a page number — are carried by the query.
     *
     * The order passes both halves of the gate before the query sees it, because this is the
     * one point every path to a row source runs through — the DB-collection helper, a
     * project table's own windowed query, and a table's hand-written SQL alike. It is held
     * against {@see sortOrders()} first, which is where an order of more than one column has to
     * have been offered, and then against {@see sortableFields()}, which is where each of its
     * components turns into a column.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Window snapshot with typed rows and metadata
     * @throws HilosException When the concrete table cannot read its row source
     */
    public function getPage(TableQueryDTO $query): TableSnapshotDTO
    {
        $sortableFields = $this->sortableFields();
        $sort = TableSortWhitelist::holdComposite($query->sort, $this->sortOrders(), $sortableFields, static::class);
        $sort = TableSortWhitelist::resolve($sort, $sortableFields, static::class);
        if ($sort !== $query->sort) {
            $query = new TableQueryDTO(
                search: $query->search,
                sort: $sort,
                limit: $query->limit,
                filter: $query->filter,
                anchor: $query->anchor,
                anchorDirection: $query->anchorDirection,
                pageIndex: $query->pageIndex,
            );
        }

        $result = $this->query($query);

        return new TableSnapshotDTO(
            rows: $this->makeRows($result->rows),
            totalCount: $result->totalCount,
            limit: $result->limit,
            firstAnchor: $result->firstAnchor,
            lastAnchor: $result->lastAnchor,
        );
    }

    // ── Actions property ─────────────────────────────────────────────────

    /**
     * Resolves table-level magic properties.
     *
     * @param string $name Property name, currently only `actions`
     * @return TableActions Table-level actions instance
     * @throws TableActionsNotConfiguredException When actions are requested before an actions class is configured
     * @throws TablePropertyNotFoundException When the property is not declared
     */
    public function __get(string $name): mixed
    {
        if ($name === TableConstants::PROPERTY_ACTIONS) {
            return $this->getActions();
        }

        throw new TablePropertyNotFoundException($name);
    }

    /**
     * Checks whether a magic property is available.
     *
     * @param string $name Property name
     * @return bool True when table-level actions are configured
     */
    public function __isset(string $name): bool
    {
        return $name === TableConstants::PROPERTY_ACTIONS && $this->_actionsClass !== null;
    }

    /**
     * Lazily creates and returns the table actions instance.
     *
     * @return TableActions Table actions instance
     * @throws TableActionsNotConfiguredException When actions class is not configured
     */
    private function getActions(): TableActions
    {
        if ($this->_actions === null) {
            if ($this->_actionsClass === null) {
                throw new TableActionsNotConfiguredException();
            }
            $this->_actions = new ($this->_actionsClass)($this);
        }
        return $this->_actions;
    }

    // ── ArrayAccess — $table->bots[$id] ──────────────────────────────────

    /**
     * Reports table row keys as addressable for item action routing.
     *
     * @param mixed $offset Row key (unused)
     * @return bool Always true
     */
    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    /**
     * Returns a TableItem wrapper for the given row key.
     *
     * @param mixed $offset Row key
     * @return TableItem Table item for the row
     */
    public function offsetGet(mixed $offset): TableItem
    {
        return new TableItem($this, $offset);
    }

    /**
     * Rejects direct row writes through ArrayAccess.
     *
     * @param mixed $offset Row key (unused)
     * @param mixed $value Value to set (unused)
     * @throws TableOffsetSetNotSupportedException Always thrown
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new TableOffsetSetNotSupportedException();
    }

    /**
     * Rejects direct row removal through ArrayAccess.
     *
     * @param mixed $offset Row key (unused)
     * @throws TableOffsetUnsetNotSupportedException Always thrown
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new TableOffsetUnsetNotSupportedException();
    }
}
