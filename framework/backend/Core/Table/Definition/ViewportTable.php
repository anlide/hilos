<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\AbstractTableRow;

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
 * getPage() and buildMutationForSourceEvent() are already concrete on
 * TableDefinition, so a TableDefinition subclass satisfies them by inheritance and
 * only browserRow() is feature-specific.
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
     * @param AbstractTableRow $row Typed table row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array;
}
