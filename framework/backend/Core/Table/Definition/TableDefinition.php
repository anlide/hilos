<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use ArrayAccess;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Exception\TableOffsetSetNotSupportedException;
use Hilos\Core\Table\Exception\TableOffsetUnsetNotSupportedException;
use Hilos\Core\Table\Exception\TablePropertyNotFoundException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Item\TableItem;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\Table\TableSearchTerm;
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
     * Names the work this table has running right now; by default there is none.
     *
     * A table with nothing running pays nothing for the channel: it declares no bars here and
     * builds none below, and the fan-out branch ends at the first of the two. Overriding either
     * is what a table does when it has work worth showing, and not a duty of every table.
     *
     * @return list<TableProgressDTO> Bars running on this table now, empty when none are
     * @throws InvalidArgumentException When an override builds a bar with a row key its place refuses
     */
    public function progressSnapshot(): array
    {
        return [];
    }

    /**
     * Builds the progress bar one source change says to show; by default a change says nothing.
     *
     * @param SourceChange $change Source change that may report work on this table
     * @return ?TableProgressDTO Bar to fan out, or null when this table reads no work in the change
     * @throws InvalidArgumentException When an override builds a bar with a row key its place refuses
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO
    {
        return null;
    }

    /**
     * Creates a row mutation DTO for source-change fan-out.
     *
     * @param TableMutationType $type Mutation type
     * @param string|int $rowKey Affected table row key
     * @param ?AbstractTableRow $row Row payload for create/update mutations
     * @return TableRowMutationDTO Row mutation payload
     */
    protected function mutation(
        TableMutationType $type,
        string|int $rowKey,
        ?AbstractTableRow $row = null,
    ): TableRowMutationDTO {
        return new TableRowMutationDTO($type, $rowKey, $row);
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
     * Declares which fields of this table's rows the search reads.
     *
     * An empty map is a full declaration and means this table has no search: a window that asks it
     * to search anyway is refused out loud rather than served a set that quietly ignores the term.
     * Every table shown today declares its own map, so the refusal is reachable only by a new table
     * whose author has not written one yet - which is who it is for.
     *
     * The map is `wire row-field name => column`, the shape {@see sortableFields()} is written in,
     * and it is read from both ends: the database searches by its values and the in-memory filter
     * by its keys, so the keys are the names a row payload is keyed by and nothing else. How far a
     * value may go depends on who runs the query, exactly as it does for a sorted column - a table
     * assembling its own SQL may qualify it with its own alias (`nd.created_at`), while a table
     * whose rows come from the ORM must name a bare column of its entity.
     *
     * What belongs in it is what a reader looks at and searches by with a word: names, titles,
     * keys they read, the text of an error. Counters, sizes, durations, dates and flags do not,
     * because nobody looks for a row by typing part of a number they never saw.
     *
     * @return array<string, string> Searched fields mapped to their columns; empty by default
     */
    protected function searchableFields(): array
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
     * Every component names a field of {@see sortableFields()}: a declaration that names a field
     * outside the map is passed over as though it were not written, because it has no column to
     * reach. Directions may differ from one component to the next, as long as the index under the
     * order is declared with the same directions. The primary key is not part of a declaration —
     * the query boundary settles the order with it.
     *
     * Every declared order is served both ways: as declared and as its mirror, every direction
     * turned. The mirror is not declared — the index under an order serves it by being read
     * backwards, which is what a window going back asks of that index already — and the menu
     * offers it right after its original. An order that is not the exact mirror of a declared
     * one is a declaration of its own, with an index of its own.
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
     * Declares how many rows the first window of this table carries.
     *
     * The first window is built when the page is subscribed, before any view of it has mounted,
     * so its size has to be known on this side: there is no client in that moment to ask. What
     * used to be a `pageSize` option on the frontend controller is this declaration, and there
     * is one of it rather than two — the window says its own size on arrival and the controller
     * reads it from there.
     *
     * Every later window keeps whatever size its request carried, so this is the size of the
     * first one and not a ceiling on the rest.
     *
     * @return int Rows the first window carries; TableConstants::DEFAULT_WINDOW_SIZE by default
     */
    public function windowSize(): int
    {
        return TableConstants::DEFAULT_WINDOW_SIZE;
    }

    /**
     * Declares the order the first window of this table runs in.
     *
     * Null is a real declaration and means the rows arrive in the order the source hands them
     * over — the same thing a table with no order declared has always done.
     *
     * A declaration here is held to the same vocabulary as any other order of this table: it
     * travels as the window's order into {@see getPage()}, where {@see sortOrders()} and
     * {@see sortableFields()} judge it exactly as they judge one a window asked for. A
     * declaration naming a field outside the map costs the first window its ordering and says
     * so in the log, which is what a table gets for declaring an order it does not serve; no
     * second way of checking an order is introduced for the sake of this one.
     *
     * @return ?TableSortOrderDTO Order the first window runs in, or null for the source's own order
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return null;
    }

    /**
     * Declares the mass operations this table accepts, by the action name each one runs under.
     *
     * Empty is a full declaration and the one most tables make: no mass operation is taken over
     * them, whatever a client sends. A table offering one names the very action its page declares
     * in ACTIONS, the name the frontend's bulk operation sends.
     *
     * @return list<string> Action names a bulk run over this table may carry; empty by default
     */
    public function bulkActions(): array
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
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
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
            totalExact: $result[TableConstants::RESULT_KEY_TOTAL_EXACT],
            limit: $query->limit,
            firstAnchor: $result[TableConstants::RESULT_KEY_FIRST_ANCHOR],
            lastAnchor: $result[TableConstants::RESULT_KEY_LAST_ANCHOR],
            rowsBefore: $result[TableConstants::RESULT_KEY_ROWS_BEFORE],
            frame: $result[TableConstants::RESULT_KEY_FRAME],
        );
    }

    /**
     * Answers whether one row belongs to the set a window query describes, using a DB collection.
     *
     * This is the {@see queryDbCollection()} of the count path: a table whose rows come from one
     * collection answers {@see ViewportTable::containsRow()} with this and nothing else.
     *
     * What the row is placed against is exactly what the collection builds out of the query —
     * the search, and nothing more. That is the same condition the collection windows by, so a
     * table served straight from it can hand the question over and be sure of the answer. **A
     * table that narrows the set further in its own {@see query()} must not**: the helper would
     * answer about a wider set than the window shows, and the live count would drift with
     * nothing failing. Such a table answers {@see ViewportTable::containsRow()} itself, against
     * its own conditions, or leaves it at "cannot say" and keeps the whole-set re-query.
     *
     * A collection with no object layer answers null rather than false: it cannot run the query,
     * and "no" from a source that was never asked is the one answer that would move the count
     * wrongly.
     *
     * @param DbCollection $collection Db collection used as the row source
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the row is in the set, or null when the collection cannot answer
     * @throws DatabaseException When query execution fails
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function containsRowInDbCollection(
        DbCollection $collection,
        string|int $rowKey,
        TableQueryDTO $query,
    ): ?bool {
        return $collection->getObjectCollection() === null ? null : $collection->containsRow($query, $rowKey);
    }

    /**
     * Filters, sorts and paginates rows this table already holds in memory.
     *
     * This is the seam that hands the in-memory filter the field the tie-breaker reads, taken
     * from the row class this table registered — the one place that knows both. Rows whose
     * payload does not carry that field cannot be told apart by the tie-breaker, so the window
     * says so once rather than once per row: an in-memory set runs to thousands of them.
     *
     * The declared searched fields are held against the same set for the same reason, and are
     * refused rather than warned about: a row set that carries none of a declared field searches
     * narrower than the table promised, and nothing about the shorter answer says so.
     *
     * @param list<array<string, mixed>> $rows All rows the table holds
     * @param TableQueryDTO $query Query parameters
     * @return TableSnapshotDTO Filtered/sorted/paginated snapshot
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
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

        $this->holdSearchFields($rows, $query);

        return InMemoryTableFilter::apply($rows, $query, $keyField);
    }

    /**
     * Answers whether one row is in the set a table holding its rows in memory would window.
     *
     * The rows come in narrowed by the table's own filters, the way the table hands them to
     * {@see self::filterInMemory()}, and the search is the same one that method runs, with the
     * declared fields held against the set the same way. What is left out is the sort and the
     * window: a yes or no about one row needs neither, and on the road that asks it - every foreign
     * write, every filtered window - they would cost more than the answer.
     *
     * @param list<array<string, mixed>> $rows Rows of the set, already narrowed by the table's own filters
     * @param string|int $rowKey Row key to look for
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return bool Whether a row with that key is in the set
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function containsRowInMemory(array $rows, string|int $rowKey, TableQueryDTO $query): bool
    {
        $keyField = $this->getRowClass()::keyField();

        $this->holdSearchFields($rows, $query);

        return array_any(
            InMemoryTableFilter::searched($rows, $query),
            static fn(array $row): bool => isset($row[$keyField]) && (string) $row[$keyField] === (string) $rowKey,
        );
    }

    /**
     * Holds the fields a search runs over against the rows it is about to run over.
     *
     * Nothing is checked when no term arrived, because an unsearched window is not the place to
     * judge a declaration, and the whole check costs one pass per declared field over rows that
     * are in hand already - the first row carrying the field ends it.
     *
     * @param list<array<string, mixed>> $rows All rows the table holds
     * @param TableQueryDTO $query Query parameters
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    private function holdSearchFields(array $rows, TableQueryDTO $query): void
    {
        if (TableSearchTerm::normalize($query->search) === null) {
            return;
        }
        if ($query->searchableFields === []) {
            throw new TableSearchNotSupportedException(static::class);
        }
        if ($rows === []) {
            return;
        }

        foreach (array_keys($query->searchableFields) as $field) {
            if (!array_any($rows, static fn(array $row): bool => array_key_exists($field, $row))) {
                throw new TableSearchFieldUnknownException(static::class, $field, $field);
            }
        }
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
     * Puts the fields this table declares the search over into the query, or refuses the search.
     *
     * This is the one place the declaration is read, and it is read late - on the query, not at
     * startup - because an empty map is a legitimate declaration and no gate could tell it from a
     * forgotten one. A window that carries no term is handed back untouched, so a table with no
     * search is only ever refused when something actually asked it to search.
     *
     * @param TableQueryDTO $query Window query the search travels in
     * @return TableQueryDTO Query carrying the declared fields, or the same one when nothing is searched
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     */
    public function scopeSearch(TableQueryDTO $query): TableQueryDTO
    {
        if (TableSearchTerm::normalize($query->search) === null) {
            return $query;
        }

        $searchableFields = $this->searchableFields();
        if ($searchableFields === []) {
            throw new TableSearchNotSupportedException(static::class);
        }

        return $query->withSearchScope($searchableFields);
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
     * The search passes {@see scopeSearch()} in the same breath and for the same reason: whichever
     * way the rows are read, the fields the term is compared with are the ones this table declared
     * and no others.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Window snapshot with typed rows and metadata
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
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

        $result = $this->query($this->scopeSearch($query));

        return new TableSnapshotDTO(
            rows: $this->makeRows($result->rows),
            totalCount: $result->totalCount,
            totalExact: $result->totalExact,
            limit: $result->limit,
            firstAnchor: $result->firstAnchor,
            lastAnchor: $result->lastAnchor,
            rowsBefore: $result->rowsBefore,
            frame: $result->frame,
        );
    }

    /**
     * Answers whether one row belongs to the set a window query describes.
     *
     * The default is "cannot say", which is what keeps a table that knows nothing of this
     * contract on the road it was already on: the live count re-queries the set for it, exactly
     * as before. A table that can answer overrides this — most of them by handing the question
     * to {@see containsRowInDbCollection()}.
     *
     * A table whose rows are already in PHP memory answers too, through
     * {@see containsRowInMemory()} over the same narrowed rows it windows. What the answer buys
     * there is not a cheaper count but word of a new row: a window with a filter or a search has
     * its arriving rows placed only once the set is known to hold them, so a table that cannot
     * say leaves such a window with a count and no announcement. The count gets cheaper on the
     * way - "cannot say" makes it re-read the whole window, sort and slice included, once per
     * flush of changes that reach the window.
     *
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query whose search and filters describe the set
     * @return ?bool Whether the row is in the set, or null when this table cannot answer
     * @throws Throwable Whatever the concrete table's row source raises
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return null;
    }

    /**
     * Reads one row of this table's own set by its key, walking the whole set.
     *
     * The set is read the way a window with nothing narrowing it is read - no search, no filter,
     * no limit - and the row is picked out by its key. That is a pass over the whole source, and it
     * is the default because the question is rare: it is put once per change to a row a tab holds
     * in focus for an open dialog, and only after that row has left the tab's window. A table with
     * an index to answer from overrides this.
     *
     * @param string|int $rowKey Key of the row to read
     * @return ?AbstractTableRow The row as the table serves it, or null when the table's own set does not hold it
     * @throws HilosException When the concrete table cannot read its row source
     */
    public function findRow(string|int $rowKey): ?AbstractTableRow
    {
        foreach ($this->getPage(new TableQueryDTO())->rows as $row) {
            if ($row instanceof AbstractTableRow && (string) $row->getRowKey() === (string) $rowKey) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Counts how many rows each option of the table's filters would leave.
     *
     * The default is "cannot count", and it is the default because a filter key means nothing to
     * this class: the condition it becomes is written inside the concrete table. A table that
     * declares filters overrides this and hands its own count of a set to
     * {@see TableFacetTally::forFilters()}.
     *
     * There is no helper for a table served straight from one collection, unlike
     * {@see containsRowInDbCollection()}: no such table carries filters of its own today, and a
     * helper with no caller would describe a guess.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return ?array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by
     *     filter key, or null when this table cannot count
     * @throws Throwable Whatever the concrete table's row source raises
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        return null;
    }

    /**
     * Places one row against a boundary of a window, in the order that window asked for.
     *
     * The default serves the table windowed in memory, which is nearly every one of them: its
     * anchors are the row payload's own fields, so the row is placed by the comparison the
     * window itself was sorted and sliced by — {@see InMemoryTableFilter::compare()}, reached
     * here rather than written again so that one order cannot be described two ways.
     *
     * "Cannot say" is answered in two cases, and both of them are a place that does not exist
     * rather than one this method failed to find. A window that asked for no order is held in
     * the row source's own sequence, which no comparison of field values reproduces. And an
     * anchor that does not carry every field of the order is not an anchor of this order at
     * all: it belongs to a table that writes its boundaries in its own columns (the
     * delivery-logs table anchors by `created_at` where its row payload says `createdAt`), or
     * to a window that was served without the order it is asking about.
     * Comparing across either gap reads missing values as nulls and answers with a sign that
     * was never computed from anything.
     *
     * A table that anchors in its own names overrides this against those names. Leaving it is
     * also a full answer: its rows keep the road they were already on, the count.
     *
     * @param AbstractTableRow $row Row to place
     * @param TableAnchorDTO $anchor Boundary of the window the row is placed against
     * @param TableQueryDTO $query Window query whose sort names the order the place is read in
     * @return ?int Negative above the anchor, zero at it, positive below it, or null when this table cannot say
     */
    public function placeRowAgainst(AbstractTableRow $row, TableAnchorDTO $anchor, TableQueryDTO $query): ?int
    {
        if ($query->sort === null) {
            return null;
        }

        $rowClass = $this->getRowClass();
        $keyField = $rowClass::keyField();
        $values = $row->toArray();
        foreach (InMemoryTableFilter::anchorFields($query->sort, $keyField) as $field) {
            if (!array_key_exists($field, $values) || !array_key_exists($field, $anchor->values)) {
                return null;
            }
        }

        return InMemoryTableFilter::compare($values, $anchor->values, $query->sort, $keyField);
    }

    /**
     * Names the place one row sits at in the order a window asked for.
     *
     * The default serves the table windowed in memory: its boundaries are its row payload's own
     * fields, so the place is the values of the very fields that order and slice the window —
     * {@see InMemoryTableFilter::anchorFields()} names them, and naming them here a second time
     * would be a second description of one order.
     *
     * "Cannot say" is answered for a window that asked for no order, which is held in the row
     * source's own sequence and has no place to read off values, and for a row missing a field
     * the order is settled by, which has no place in that order at all. Both are the same
     * silence {@see placeRowAgainst()} answers, and for the same reason: an anchor built out of
     * the fields that happened to be there names a place computed from nothing, and the place
     * decides whether a shown row is reported as having moved.
     *
     * @param AbstractTableRow $row Row to name the place of
     * @param TableQueryDTO $query Window query whose sort names the order the place is read in
     * @return ?TableAnchorDTO Place the row sits at in that order, or null when this table cannot say
     */
    public function anchorForRow(AbstractTableRow $row, TableQueryDTO $query): ?TableAnchorDTO
    {
        if ($query->sort === null) {
            return null;
        }

        $rowClass = $this->getRowClass();
        $keyField = $rowClass::keyField();
        $values = $row->toArray();
        $anchorFields = InMemoryTableFilter::anchorFields($query->sort, $keyField);
        foreach ($anchorFields as $field) {
            if (!array_key_exists($field, $values)) {
                return null;
            }
        }

        return TableAnchorDTO::fromRow($values, $anchorFields);
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
