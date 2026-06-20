<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Contract for a table that delivers its own browser rows.
 *
 * A self-snapshot table produces its browser rows from its own full snapshot
 * (initial subscription) and from a source-change mutation (reactive update),
 * instead of the BrowserContext DB/RT source fan-out. This fits tables whose row
 * set is not a direct projection of source items — settings, where catalog keys
 * without a persisted DB row must still appear as placeholder rows.
 *
 * The base BrowserContext reads getFullSnapshot() and buildMutationForSourceEvent(),
 * then serializes each row with browserRow(). A TableDefinition subclass satisfies
 * the first two methods by inheritance, so only browserRow() is feature-specific.
 */
interface SelfSnapshotTable
{
    /**
     * Loads the complete table snapshot for an initial page subscription.
     *
     * @return TableSnapshotDTO Full snapshot with typed rows
     */
    public function getFullSnapshot(): TableSnapshotDTO;

    /**
     * Builds a row mutation for one source change this table reacts to.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation to fan out, or null when the table is unaffected
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO;

    /**
     * Serializes one table row into its internal browser-row envelope.
     *
     * The envelope carries the logical row key and the per-source slot fragments;
     * the base BrowserContext routes it into the page payload, renaming the source
     * fragments to wire slots. Returning the internal `{rowKey, sources}` shape lets
     * a self-snapshot row reuse the same payload assembly as source-fanout rows.
     *
     * @param AbstractTableRow $row Typed table row from this table's snapshot or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array;
}
