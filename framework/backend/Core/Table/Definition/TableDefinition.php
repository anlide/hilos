<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use ArrayAccess;
use Hilos\Core\Exception\NotImplementedException;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TablePageQueryDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

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
     * @return class-string<AbstractTableRow>
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
     * Builds a table row mutation for one source change this table reacts to.
     *
     * Concrete tables decide whether the change affects their projection and
     * which DB/RT collections they observe. A table may react to one or more DB
     * sources, one or more RT sources, or any combination; the change kind and
     * source key are carried by the SourceChange DTO.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Mutation to fan out, or null when the table is unaffected
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Creates a row mutation DTO for source-event fan-out.
     *
     * @param TableMutationType $type Mutation type
     * @param string|int $rowKey Affected table row key
     * @param ?AbstractTableRow $row Row payload for create/update mutations
     * @return TableRowMutationDTO Row mutation payload
     */
    protected function mutation(TableMutationType $type, string|int $rowKey, ?AbstractTableRow $row = null): TableRowMutationDTO
    {
        return new TableRowMutationDTO($type, $rowKey, $row);
    }

    /**
     * Loads table data for the given table query.
     *
     * Each concrete table owns its row source and may combine DB, runtime,
     * config, SQL aggregates, or any other data needed for its row shape.
     *
     * @param TableQueryDTO $query Query parameters
     * @return TableSnapshotDTO Snapshot with raw or typed rows
     */
    abstract protected function query(TableQueryDTO $query): TableSnapshotDTO;

    /**
     * Queries a DB collection with the standard table search, sort, and pagination behavior.
     *
     * This helper is intended for simple tables whose rows are direct frontend
     * projections of a single DbCollection. Tables with joined, calculated, or
     * runtime-enriched rows should implement {@see self::query()} directly.
     *
     * @param DbCollection $collection Db collection used as the row source
     * @param TableQueryDTO $query Query parameters
     * @return TableSnapshotDTO Snapshot with raw rows
     * @throws DatabaseException When query execution fails
     */
    protected function queryDbCollection(DbCollection $collection, TableQueryDTO $query): TableSnapshotDTO
    {
        $objectCollection = $collection->getObjectCollection();

        if ($objectCollection === null || $objectCollection->isAllLoaded()) {
            $rows = $collection->toArray(idAsIndex: false, toFrontend: true);
            return InMemoryTableFilter::apply($rows, $query);
        }

        $result = $collection->queryPage($query);

        return new TableSnapshotDTO(
            rows: $result[TableConstants::RESULT_KEY_ROWS],
            totalCount: $result[TableConstants::RESULT_KEY_TOTAL_COUNT],
            offset: $query->offset,
            limit: $query->limit,
        );
    }

    /**
     * Loads a complete table snapshot for initial page rendering.
     *
     * @return TableSnapshotDTO Full snapshot with typed rows and metadata
     */
    public function getFullSnapshot(): TableSnapshotDTO
    {
        $result = $this->query(new TableQueryDTO());

        return new TableSnapshotDTO(
            rows: $this->makeRows($result->rows),
            totalCount: $result->totalCount,
            offset: $result->offset,
            limit: $result->limit,
        );
    }

    /**
     * Reserved API for future partial table loading.
     *
     * Paging is intentionally not implemented yet; current page subscriptions
     * must use a full table snapshot from {@see self::getFullSnapshot()}.
     *
     * @param TablePageQueryDTO $query Page query parameters
     * @return TableSnapshotDTO Partial table page once implemented
     * @throws NotImplementedException Always, until partial table loading is implemented
     */
    public function getPage(TablePageQueryDTO $query): TableSnapshotDTO
    {
        throw new NotImplementedException('TableDefinition::getPage() is not implemented; use getFullSnapshot() for the current full table snapshot');
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
