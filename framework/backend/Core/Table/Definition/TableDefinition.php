<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use ArrayAccess;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TableOffsetSetNotSupportedException;
use Hilos\Core\Table\Exception\TableOffsetUnsetNotSupportedException;
use Hilos\Core\Table\Exception\TablePropertyNotFoundException;
use Hilos\Core\Table\Item\TableItem;

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
    private ?TableActions $_actions = null;

    /** @var ?class-string<TableActions> */
    private ?string $_actionsClass = null;

    /** @var ?class-string<TableItemActions> */
    private ?string $_itemActionsClass = null;

    public function __construct(
        private readonly TableDataSourceInterface $dataSource,
    ) {
        $this->init();
    }

    /**
     * Override in subclasses to configure actions classes.
     */
    protected function init(): void
    {
    }

    /**
     * @param class-string<TableActions> $class
     */
    protected function setActionsClass(string $class): void
    {
        $this->_actionsClass = $class;
    }

    /**
     * @param class-string<TableItemActions> $class
     */
    protected function setItemActionsClass(string $class): void
    {
        $this->_itemActionsClass = $class;
    }

    /**
     * @return ?class-string<TableItemActions>
     */
    public function getItemActionsClass(): ?string
    {
        return $this->_itemActionsClass;
    }

    public function getDataSource(): TableDataSourceInterface
    {
        return $this->dataSource;
    }

    // ── Stateless query ──────────────────────────────────────────────────

    /**
     * Get table data (stateless). Pulls from data source, applies search/sort/pagination.
     *
     * @param string $search Full-text search across row values
     * @param string $orderBy Field name to order by (empty = no ordering)
     * @param string $orderDirection 'asc' or 'desc'
     * @param int $offset Zero-based offset for pagination
     * @param int $limit Page size (0 = no limit)
     */
    public function get(
        string $search = '',
        string $orderBy = '',
        string $orderDirection = 'asc',
        int $offset = 0,
        int $limit = 0,
    ): TableResultDTO {
        $rows = $this->dataSource->loadFull();

        if ($search !== '') {
            $query = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($query): bool {
                return array_any($row, fn($value) => $value !== null && str_contains(mb_strtolower((string)$value), $query));
            }));
        }

        if ($orderBy !== '') {
            $dir = strtolower($orderDirection) === 'desc' ? -1 : 1;
            usort($rows, static function (array $a, array $b) use ($orderBy, $dir): int {
                $va = $a[$orderBy] ?? null;
                $vb = $b[$orderBy] ?? null;
                if ($va === null && $vb === null) return 0;
                if ($va === null) return $dir;
                if ($vb === null) return -$dir;
                return $dir * strnatcasecmp((string) $va, (string) $vb);
            });
        }

        $totalCount = count($rows);

        if ($limit > 0) {
            $rows = array_slice($rows, $offset, $limit);
        } elseif ($offset > 0) {
            $rows = array_slice($rows, $offset);
        }

        return new TableResultDTO(
            rows: $rows,
            totalCount: $totalCount,
            offset: $offset,
            limit: $limit,
        );
    }

    // ── Actions property ─────────────────────────────────────────────────

    /**
     * @return TableActions
     */
    public function __get(string $name): mixed
    {
        if ($name === 'actions') {
            return $this->getActions();
        }

        throw new TablePropertyNotFoundException($name);
    }

    public function __isset(string $name): bool
    {
        return $name === 'actions' && $this->_actionsClass !== null;
    }

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

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): TableItem
    {
        return new TableItem($this, $offset);
    }

    /** @codeCoverageIgnore */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new TableOffsetSetNotSupportedException();
    }

    /** @codeCoverageIgnore */
    public function offsetUnset(mixed $offset): void
    {
        throw new TableOffsetUnsetNotSupportedException();
    }
}
