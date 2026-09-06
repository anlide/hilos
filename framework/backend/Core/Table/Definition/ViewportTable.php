<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\HilosException;

/**
 * Contract for a table that can serve a server-windowed viewport.
 *
 * A viewport table delivers a window of typed rows for a connection's descriptor
 * (getPage), maps a source change to a row mutation (buildMutationForSourceEvent),
 * and serializes a typed row into its browser-row envelope (browserRow). Together
 * these let the BrowserContext answer a table_viewport request with a table_window
 * and stream point-wise table_viewport_delta updates scoped to the window's rows —
 * for ANY table, whether it is a {@see SelfSnapshotTable} (settings) or a
 * source-fanned table (the Hilos users table), independent of how the table
 * delivers its non-viewport page_response rows.
 *
 * getPage(), buildMutationForSourceEvent(), containsRow() and placeRowAgainst() are
 * already concrete on TableDefinition, so a TableDefinition subclass satisfies them by
 * inheritance and only browserRow() is feature-specific.
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
     * Answers whether one row belongs to the set a window query describes.
     *
     * This is what a live count asks instead of counting the set again. A re-count under an
     * active search is a full pass over the source on every foreign write and on every
     * connection watching; the question about a single row is one indexed lookup, and it is
     * all the count needs — a row that joined the set moves it by one, a row that left moves
     * it by one the other way.
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
     * Builds a row mutation for one source change this table reacts to.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation to scope into a delta, or null when the table is unaffected
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO;

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
}
