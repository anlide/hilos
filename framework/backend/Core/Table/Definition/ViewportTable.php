<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\HilosException;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\TableFacetTally;

/**
 * Contract for a table that can serve a server-windowed viewport.
 *
 * A viewport table delivers a window of typed rows for a connection's descriptor
 * (getPage), declares the size and the order of the first of those windows (windowSize,
 * defaultSort), maps a source change to a row mutation (buildMutationForSourceEvent),
 * and serializes a typed row into its browser-row envelope (browserRow). Together
 * these let the BrowserContext answer a table_viewport request with a table_window
 * and stream point-wise table_viewport_delta updates scoped to the window's rows —
 * for ANY table, whether it is a {@see SelfSnapshotTable} (settings) or a
 * source-fanned table (the Hilos users table), independent of how the table
 * delivers its non-viewport page_response rows.
 *
 * getPage(), scopeSearch(), buildMutationForSourceEvent(), progressSnapshot(),
 * buildProgressForSourceEvent(), containsRow(), placeRowAgainst(), anchorForRow(), windowSize()
 * and defaultSort() are already concrete on TableDefinition, so a TableDefinition subclass
 * satisfies them by inheritance and only browserRow() is feature-specific.
 */
interface ViewportTable
{
    /**
     * Loads one window of the table for a viewport descriptor's query.
     *
     * @param TableQueryDTO $query Window query (search, sort, offset, limit)
     * @return TableSnapshotDTO Window snapshot with typed rows and the total count
     */
    public function getPage(TableQueryDTO $query): TableSnapshotDTO;

    /**
     * Puts the fields this table declares the search over into a query, or refuses the search.
     *
     * {@see getPage()} does this to its own query, so it stands in this contract for the one
     * caller that reaches a row source without going through a window: the question about a single
     * row a live count asks ({@see containsRow()}). Both have to describe the same set, and a
     * search scoped for one of them and not the other is exactly the drift that shows up as a
     * counter disagreeing with the rows on screen.
     *
     * @param TableQueryDTO $query Window query the search travels in
     * @return TableQueryDTO Query carrying the declared fields, or the same one when nothing is searched
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     */
    public function scopeSearch(TableQueryDTO $query): TableQueryDTO;

    /**
     * Declares how many rows the first window of this table carries.
     *
     * The first window is built by the BrowserContext when the page is subscribed, so the size
     * is asked of the table from outside it — which is why it stands in this contract and not
     * only among the table's own protected declarations. It is already concrete on
     * TableDefinition, so a subclass gets it for nothing and overrides it only to say a size of
     * its own, the same way containsRow() is inherited here.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int;

    /**
     * Declares the order the first window of this table runs in.
     *
     * Null means the rows arrive in the order the source hands them over. The declaration is
     * judged by the same gate a client-chosen order passes, because it travels into getPage()
     * as that window's order and nothing about it is trusted more for having come from here.
     *
     * @return ?TableSortOrderDTO Order the first window runs in, or null for the source's own order
     */
    public function defaultSort(): ?TableSortOrderDTO;

    /**
     * Answers whether one row belongs to the set a window query describes.
     *
     * This is what a live count asks instead of counting the set again. A re-count under an
     * active search is a full pass over the source on every foreign write and on every
     * connection watching; the question about a single row is one indexed lookup, and it is
     * all the count needs — a row that joined the set moves it by one, a row that left moves
     * it by one the other way.
     *
     * The same answer decides whether a created row reaches a window with a filter or a search
     * as anything but a count: its place against that window's boundaries is read only once the
     * set is known to hold it, so a table that cannot say leaves such a window unannounced to.
     *
     * The question goes to the row source rather than being answered from the filter map in
     * PHP, because two descriptions of one condition drift apart silently, and the drift shows
     * up as a counter nobody can explain.
     *
     * Null is a real answer and means "this table cannot say": a table with its own SQL and no
     * implementation of this keeps the re-count it had. It is the default for exactly that
     * reason — a table that knows nothing of this contract must not start reporting silence as
     * "the row is not in the set".
     *
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query whose search and filters describe the set
     * @return ?bool Whether the row is in the set, or null when this table cannot answer
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool;

    /**
     * Counts how many rows each option of the table's filters would leave.
     *
     * This is the number beside an option in the filter's dropdown. Only the table can take it:
     * the open filter map becomes a condition inside the table and nowhere else, so the framework
     * knows neither what a filter key means nor how to count by it. The options themselves are the
     * client's - a page declares them on the front end - and arrive in `$wanted`.
     *
     * Most tables answer by handing their own count of a set to {@see TableFacetTally::forFilters()},
     * which decides which sets are counted and leaves the condition written once, in the table.
     *
     * Null is a real answer and means "this table cannot count", the way it does for
     * {@see containsRow()}: the dropdown is drawn as it always was, with no numbers. A filter the
     * table does not count is absent from the map it returns, and a filter it was not asked about
     * is absent too.
     *
     * The method is also called at the end of every flush of live changes that reached a window
     * with declared options, so every count must stop at the ceiling (for SQL —
     * {@see TableFacetTally::cappedSqlCount()}), as required by table-subscription.md:717.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search
     *     scoped by {@see scopeSearch()}
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return ?array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by
     *     filter key, or null when this table cannot count
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array;

    /**
     * Places one row against a boundary of a window, in the order that window asked for.
     *
     * This is what tells an arriving row's place from the window's own edges without asking the
     * source for the window again: a row above the first boundary is on an earlier page, one
     * below the last boundary is on a later one, and one between them would push the shown rows
     * apart.
     *
     * The comparison belongs to the table because the key space of an anchor belongs to the row
     * source (HIL-787): a table windowed in memory anchors by the fields of its row payload, a
     * table with its own SQL by its own columns, and the two write the same place under
     * different names. A caller building the row's anchor itself would be comparing two maps of
     * names that agree on no installation.
     *
     * Null is a real answer and means "this table cannot say": the window asked for no order, so
     * a place cannot be read off values at all, or the anchor is written in names the row does
     * not carry. It is the honest answer where a sign would be a guess — the sign decides
     * whether a row arrives on its own, and a misplaced row either shows up where a reload would
     * not put it or never shows up at all.
     *
     * @param AbstractTableRow $row Row to place
     * @param TableAnchorDTO $anchor Boundary of the window the row is placed against
     * @param TableQueryDTO $query Window query whose sort names the order the place is read in
     * @return ?int Negative above the anchor, zero at it, positive below it, or null when this table cannot say
     */
    public function placeRowAgainst(AbstractTableRow $row, TableAnchorDTO $anchor, TableQueryDTO $query): ?int;

    /**
     * Names the place one row sits at in the order a window asked for.
     *
     * This is what lets a viewport remember where each delivered row stood, so that a later
     * edit of that row is judged against the places of its neighbours instead of against its
     * own former place. The difference is the whole question: a size going from 1,1 GB to
     * 1,4 GB between neighbours of 2 GB and 0,5 GB names a new place for the row and leaves
     * it standing exactly where it was, and a window comparing the row with its own past
     * would promise a move that pressing Apply cannot deliver.
     *
     * The anchor is written by the table for the same reason {@see placeRowAgainst()} does the
     * comparing: the key space of an anchor belongs to the row source (HIL-787), so a caller
     * naming the place itself would be writing it in names the table's own boundaries are not
     * written in — the delivery-logs table anchors by `created_at` where its row payload says
     * `createdAt`.
     *
     * Null is a real answer and means "this table cannot say", in the two cases a place does
     * not exist rather than fails to be found: a window that asked for no order is held in the
     * row source's own sequence, and a row that carries no value for a field the order is
     * settled by has no place in that order to name. A table anchoring in its own names
     * overrides this against those names; leaving it is a full answer too, and its rows are
     * then the ones a viewport cannot place.
     *
     * @param AbstractTableRow $row Row to name the place of
     * @param TableQueryDTO $query Window query whose sort names the order the place is read in
     * @return ?TableAnchorDTO Place the row sits at in that order, or null when this table cannot say
     */
    public function anchorForRow(AbstractTableRow $row, TableQueryDTO $query): ?TableAnchorDTO;

    /**
     * Builds a row mutation for one source change this table reacts to.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation to scope into a delta, or null when the table is unaffected
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO;

    /**
     * Names the work this table has running right now.
     *
     * This is what a tab opening in the middle of a run is told, and it is asked once, while the
     * page's own answer is being built. Without it the tab would see nothing until the next stir
     * of a source - which for work that reports a phase at a time can be minutes - and a bar is
     * exactly the thing a person opens the page to look at.
     *
     * The list is also the whole truth about this table's bars at that moment: the client puts up
     * the bars it names and takes down every other, an empty list clearing them all. That is the
     * one cure for a bar left standing by a connection that dropped before the work ended.
     *
     * @return list<TableProgressDTO> Bars running on this table now, empty when none are
     * @throws InvalidArgumentException When the table builds a bar with a row key its place refuses
     */
    public function progressSnapshot(): array;

    /**
     * Builds the progress bar one source change says to show, if it says to show one.
     *
     * The mirror of {@see buildMutationForSourceEvent()} for work rather than for rows: the same
     * source change arrives, and the table says what follows from it for its bars. The road is
     * the same one rows travel because it is the only one there is - progress is born inside a
     * monopolistic agent, which holds no subscription registry, so a bar reaches a tab by the
     * agent writing runtime state and a worker fanning the change out.
     *
     * @param SourceChange $change Source change that may report work on this table
     * @return ?TableProgressDTO Bar to address to the window's subscribers, or null when the change reports no work
     * @throws InvalidArgumentException When the table builds a bar with a row key its place refuses
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO;

    /**
     * Serializes one typed row into its internal browser-row envelope.
     *
     * The envelope carries the logical row key and the per-source slot fragments;
     * the base BrowserContext renames the source fragments to wire slots, the same
     * shape page_response table rows use, so a windowed row reaches the client
     * identically to a fanned-out one.
     *
     * A third, optional key names the slots this row assembled out of a copy that stopped
     * being kept up to date (HIL-800). It is the table's own answer and not the framework's,
     * because a typed table builds its fragments itself and one of them can be a summary over
     * many runtime rows — which of those went into it is known here and nowhere else. A table
     * with nothing that can fall behind writes no such key.
     *
     * @param AbstractTableRow $row Typed table row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>, staleSources?: list<string>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     * @throws HilosException When the table's own sources refuse the reads its fragments need
     */
    public function browserRow(AbstractTableRow $row): array;

    /**
     * Declares the mass operations this table accepts, by the action name each one runs under.
     *
     * The backend mirror of the operations a page offers for the marked rows on the frontend
     * (`HilosTableFrame.bulkActions`): a run is refused unless its action stands here, so a table
     * that declares none takes no mass operation at all, whatever a client sends
     * ({@see PageSignalRouter::startBulkRun()}).
     *
     * @return list<string> Action names a bulk run over this table may carry
     */
    public function bulkActions(): array;
}
