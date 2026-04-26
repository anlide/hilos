<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use ArrayAccess;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TableOffsetSetNotSupportedException;
use Hilos\Core\Table\Exception\TableOffsetUnsetNotSupportedException;
use Hilos\Core\Table\Exception\TablePropertyNotFoundException;
use Hilos\Core\Table\Item\TableItem;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Base table definition — one per registered table.
 *
 * Stateless: each get() call pulls fresh data through the table query.
 * Supports ArrayAccess for item-level operations: $table->bots[$id]->actions->delete().
 *
 * @implements ArrayAccess<string|int, TableItem>
 */
abstract class TableDefinition implements ArrayAccess
{
    /** @var ?TableActions lazy-loaded table-level actions instance */
    private ?TableActions $_actions = null;

    /** @var ?class-string<TableActions> table actions class for create, etc */
    private ?string $_actionsClass = null;

    /** @var ?class-string<TableItemActions> item actions class for update, delete */
    private ?string $_itemActionsClass = null;

    /** @var class-string<AbstractTableRow> row class used by this table */
    private string $_rowClass = GenericTableRow::class;

    /**
     * Creates table definition and applies table-specific configuration.
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Override in subclasses to configure actions classes.
     *
     * Called from the constructor after the base table state is initialized.
     */
    protected function init(): void
    {
    }

    /**
     * Registers the table-level actions class (create, etc.).
     *
     * @param class-string<TableActions> $class Table actions class name
     */
    protected function setActionsClass(string $class): void
    {
        $this->_actionsClass = $class;
    }

    /**
     * Registers the item-level actions class (update, delete).
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
     * @return class-string<AbstractTableRow>
     */
    public function getRowClass(): string
    {
        return $this->_rowClass;
    }

    /**
     * Builds one row object from an array payload.
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
     * Builds row objects for each payload array.
     *
     * @param list<AbstractTableRow|array<string, mixed>> $rows Raw row payloads
     * @return list<AbstractTableRow>
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
     * Loads table data for the given table query.
     *
     * Each concrete table owns its row source and may combine DB, runtime,
     * config, SQL aggregates, or any other data needed for its row shape.
     *
     * @param TableQueryDTO $query Query parameters
     * @return TableResultDTO Query result with raw or typed rows
     */
    abstract protected function query(TableQueryDTO $query): TableResultDTO;

    /**
     * Queries a DbCollection using the standard table search, sort and pagination behavior.
     *
     * This helper is intended for simple tables whose rows are direct frontend
     * projections of a single DbCollection. Tables with joined, calculated, or
     * runtime-enriched rows should implement {@see self::query()} directly.
     *
     * @param DbCollection $collection Db collection used as the row source
     * @param TableQueryDTO $query Query parameters
     * @return TableResultDTO Query result with raw rows
     * @throws DatabaseException If query execution fails
     */
    protected function queryDbCollection(DbCollection $collection, TableQueryDTO $query): TableResultDTO
    {
        $objectCollection = $collection->getObjectCollection();

        if ($objectCollection === null || $objectCollection->isAllLoaded()) {
            $rows = $collection->toArray(idAsIndex: false, toFrontend: true);
            return InMemoryTableFilter::apply($rows, $query);
        }

        $result = $collection->queryPage($query);

        return new TableResultDTO(
            rows: $result[TableConstants::RESULT_KEY_ROWS],
            totalCount: $result[TableConstants::RESULT_KEY_TOTAL_COUNT],
            offset: $query->offset,
            limit: $query->limit,
        );
    }

    /**
     * Loads table data (stateless). Delegates query construction to the concrete table.
     *
     * @param string $search Full-text search across row values
     * @param string $orderBy Field name to order by (empty = no ordering)
     * @param string $orderDirection TableConstants::ORDER_ASC or ORDER_DESC
     * @param int $offset Zero-based offset for pagination
     * @param int $limit Page size (NO_LIMIT = all rows)
     * @return TableResultDTO Query result with rows and total count
     */
    public function get(
        string $search = '',
        string $orderBy = '',
        string $orderDirection = TableConstants::ORDER_ASC,
        int $offset = 0,
        int $limit = TableConstants::NO_LIMIT,
    ): TableResultDTO {
        $result = $this->query(new TableQueryDTO(
            search: $search,
            orderBy: $orderBy,
            orderDirection: $orderDirection,
            offset: $offset,
            limit: $limit,
        ));

        return new TableResultDTO(
            rows: $this->makeRows($result->rows),
            totalCount: $result->totalCount,
            offset: $result->offset,
            limit: $result->limit,
        );
    }

    // ── Actions property ─────────────────────────────────────────────────

    /**
     * Magic getter for table-level actions property.
     *
     * @param string $name Property name (PROPERTY_ACTIONS for actions)
     * @return TableActions Table actions instance
     * @throws TablePropertyNotFoundException If the property does not exist
     */
    public function __get(string $name): mixed
    {
        if ($name === TableConstants::PROPERTY_ACTIONS) {
            return $this->getActions();
        }

        throw new TablePropertyNotFoundException($name);
    }

    /**
     * Magic isset for properties available via __get.
     *
     * @param string $name Property name
     * @return bool True if property exists and is configurable
     */
    public function __isset(string $name): bool
    {
        return $name === TableConstants::PROPERTY_ACTIONS && $this->_actionsClass !== null;
    }

    /**
     * Lazily creates and returns the table actions instance.
     *
     * @return TableActions Table actions instance
     * @throws TableActionsNotConfiguredException If actions class is not configured
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
     * ArrayAccess: always returns true (item creation is deferred to offsetGet).
     *
     * @param mixed $offset Row key (unused)
     * @return bool Always true
     */
    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    /**
     * ArrayAccess: returns a TableItem for the given row key.
     *
     * @param mixed $offset Row key
     * @return TableItem Table item for the row
     */
    public function offsetGet(mixed $offset): TableItem
    {
        return new TableItem($this, $offset);
    }

    /**
     * ArrayAccess: set is not supported (tables are read-only).
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
     * ArrayAccess: unset is not supported (tables are read-only).
     *
     * @param mixed $offset Row key (unused)
     * @throws TableOffsetUnsetNotSupportedException Always thrown
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new TableOffsetUnsetNotSupportedException();
    }
}
