<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use ArrayAccess;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TableDataSourceNotProvidedException;
use Hilos\Core\Table\Exception\TableOffsetSetNotSupportedException;
use Hilos\Core\Table\Exception\TableOffsetUnsetNotSupportedException;
use Hilos\Core\Table\Exception\TablePropertyNotFoundException;
use Hilos\Core\Table\Item\TableItem;
use Hilos\Core\Table\TableConstants;

/**
 * Base table definition — one per registered table.
 *
 * Stateless: each get() call pulls fresh data from the data source.
 * Supports ArrayAccess for item-level operations: $table->bots[$id]->actions->delete()
 *
 * @implements ArrayAccess<string|int, TableItem>
 */
abstract class TableDefinition implements ArrayAccess
{
    /** Data source for loading rows (entity collection, view, etc.). */
    private readonly TableDataSourceInterface $dataSource;

    /** Lazy-loaded table-level actions instance. */
    private ?TableActions $_actions = null;

    /** @var ?class-string<TableActions> Table actions class for create, etc. */
    private ?string $_actionsClass = null;

    /** @var ?class-string<TableItemActions> Item actions class for update, delete. */
    private ?string $_itemActionsClass = null;

    /**
     * @param ?TableDataSourceInterface $dataSource Optional. If null, createDataSource() is called.
     */
    public function __construct(?TableDataSourceInterface $dataSource = null)
    {
        $this->dataSource = $dataSource ?? $this->createDataSource();
        $this->init();
    }

    /**
     * Override to provide data source when constructor is called without args.
     * Subclasses that support parameterless construction must override.
     *
     * @return TableDataSourceInterface
     *
     * @throws TableDataSourceNotProvidedException If not overridden and no data source passed
     */
    protected function createDataSource(): TableDataSourceInterface
    {
        throw new TableDataSourceNotProvidedException();
    }

    /**
     * Override in subclasses to configure actions classes.
     */
    protected function init(): void
    {
    }

    /**
     * Registers the table-level actions class (create, etc.).
     *
     * @param class-string<TableActions> $class
     */
    protected function setActionsClass(string $class): void
    {
        $this->_actionsClass = $class;
    }

    /**
     * Registers the item-level actions class (update, delete).
     *
     * @param class-string<TableItemActions> $class
     */
    protected function setItemActionsClass(string $class): void
    {
        $this->_itemActionsClass = $class;
    }

    /**
     * Returns the registered item actions class, or null if not configured.
     *
     * @return ?class-string<TableItemActions>
     */
    public function getItemActionsClass(): ?string
    {
        return $this->_itemActionsClass;
    }

    /**
     * Returns the data source used for loading table rows.
     *
     * @return TableDataSourceInterface
     */
    public function getDataSource(): TableDataSourceInterface
    {
        return $this->dataSource;
    }

    // ── Stateless query ──────────────────────────────────────────────────

    /**
     * Loads table data (stateless). Delegates query to the data source.
     *
     * @param string $search Full-text search across row values
     * @param string $orderBy Field name to order by (empty = no ordering)
     * @param string $orderDirection TableConstants::ORDER_ASC or TableConstants::ORDER_DESC
     * @param int $offset Zero-based offset for pagination
     * @param int $limit Page size (TableConstants::NO_LIMIT = all rows)
     *
     * @return TableResultDTO
     */
    public function get(
        string $search = '',
        string $orderBy = '',
        string $orderDirection = TableConstants::ORDER_ASC,
        int $offset = 0,
        int $limit = TableConstants::NO_LIMIT,
    ): TableResultDTO {
        return $this->dataSource->query(new TableQueryDTO(
            search: $search,
            orderBy: $orderBy,
            orderDirection: $orderDirection,
            offset: $offset,
            limit: $limit,
        ));
    }

    // ── Actions property ─────────────────────────────────────────────────

    /**
     * Magic getter for table-level actions property.
     *
     * @param string $name Property name (TableConstants::PROPERTY_ACTIONS for actions)
     *
     * @return TableActions
     *
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
     *
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $name === TableConstants::PROPERTY_ACTIONS && $this->_actionsClass !== null;
    }

    /**
     * Lazily creates and returns the table actions instance.
     *
     * @return TableActions
     *
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
     * @param mixed $offset Row ID (unused, always returns true)
     *
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    /**
     * ArrayAccess: returns a TableItem for the given row ID.
     *
     * @param mixed $offset Row ID
     *
     * @return TableItem
     */
    public function offsetGet(mixed $offset): TableItem
    {
        return new TableItem($this, $offset);
    }

    /**
     * ArrayAccess: set is not supported (tables are read-only).
     *
     * @param mixed $offset Row ID (unused)
     * @param mixed $value Value to set (unused)
     *
     * @throws TableOffsetSetNotSupportedException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new TableOffsetSetNotSupportedException();
    }

    /**
     * ArrayAccess: unset is not supported (tables are read-only).
     *
     * @param mixed $offset Row ID (unused)
     *
     * @throws TableOffsetUnsetNotSupportedException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new TableOffsetUnsetNotSupportedException();
    }
}
