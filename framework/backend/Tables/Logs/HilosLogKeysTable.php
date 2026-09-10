<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSortWhitelist;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\LogKeySummary;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;

/**
 * Framework log-keys table: the log streams of every node, one row per key on a node (HIL-385).
 *
 * Rows are built out of {@see ClusterLogIndexMirror} and out of nothing else — the picture the
 * nodes reported, held in the page worker's memory. It walks no directory: a walk here would block
 * the worker on file I/O and could only ever see the node it happens to run on, which is one
 * machine's store shown as though it were the installation's.
 *
 * A {@see ViewportTable}, so a window is served whole rather than assembled from row deltas: the
 * mirror is neither a DB nor a runtime source, raises no {@see SourceChange}, and there is
 * therefore nothing a delta could be built from. The page re-sends the window when the mirror's
 * fingerprint moves ({@see AbstractHilosLogsKeysPage::onAgentTick()}).
 *
 * Streams of the daemon's own class are dropped HERE rather than by the view. The section's mockup
 * knows two classes and the overview counts two, while {@see LogKeySummary} has three; a row
 * thrown away by the view would still take its place in the total count and make the pager promise
 * a page that holds nothing. The daemon's streams get their own screens once the section's mockups
 * are redrawn (proposal P-218).
 */
final class HilosLogKeysTable extends TableDefinition implements ViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosLogKeys';

    /** Filter-map key: narrow the streams to one cluster node (absent in a single-node installation). */
    public const string FILTER_NODE = 'node';

    /** Filter-map key: narrow the streams to one class, {@see LogKeySummary::CLASS_AGENT} or {@see LogKeySummary::CLASS_WORKER}. */
    public const string FILTER_CLASS = 'class';

    /** Wire slot the row payload rides under; must match the frontend stream slot. */
    private const string ROW_SLOT = 'stream';

    /** Separator between the node and the log key inside a row key. */
    private const string ROW_KEY_SEPARATOR = ':';

    /** Stands in for the node in a row key when the installation has no node id at all. */
    private const string ROW_KEY_NODELESS = '-';

    /** Growth of a stream whose measuring window has not filled yet, as the ordering reads it. */
    private const int GROWTH_UNKNOWN = -1;

    /**
     * Declares how many rows the first window of the log keys table carries.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 25;
    }

    /**
     * Declares the order the first window of the log keys table runs in.
     *
     * @return ?TableSortOrderDTO First window ordered by bytes descending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLogKeysTableRow::bytes, TableConstants::ORDER_DESC));
    }

    /**
     * The stream list has no live per-row source; a window refresh is a re-projection.
     *
     * @param SourceChange $change Source change (ignored)
     * @return ?TableRowMutationDTO Always null — the mirror raises no source events
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Serializes one stream row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Log-keys table row from this table's window
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Declares the stream list's sortable columns and the payload key each one orders by.
     *
     * Four of the five order by themselves. The daily growth is the exception: it is drawn from
     * {@see HilosLogKeysTableRow::growthPerDay}, which is null while the measuring window fills,
     * and ordered by {@see HilosLogKeysTableRow::growthSort}, which mints that unknown as -1 —
     * without the swap a descending sort would open with the rows nothing is known about, because
     * {@see InMemoryTableFilter} puts a null on top of one.
     *
     * @return array<string, string> Wire row fields mapped to the payload keys they order by
     */
    protected function sortableFields(): array
    {
        return [
            HilosLogKeysTableRow::key => HilosLogKeysTableRow::key,
            HilosLogKeysTableRow::node => HilosLogKeysTableRow::node,
            HilosLogKeysTableRow::batchCount => HilosLogKeysTableRow::batchCount,
            HilosLogKeysTableRow::bytes => HilosLogKeysTableRow::bytes,
            HilosLogKeysTableRow::growthPerDay => HilosLogKeysTableRow::growthSort,
        ];
    }

    /**
     * Declares what a stream row is searched by: the key that is the file's name, the node it
     * runs on, and the class of process behind it.
     *
     * The weight, the batch count and the growth are numbers an operator reads and nobody types
     * part of, so they stay out: a short term matching them would match nearly every row.
     *
     * @return array<string, string> Searched fields mapped to themselves, these rows being searched in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosLogKeysTableRow::key => HilosLogKeysTableRow::key,
            HilosLogKeysTableRow::node => HilosLogKeysTableRow::node,
            HilosLogKeysTableRow::streamClass => HilosLogKeysTableRow::streamClass,
        ];
    }

    /**
     * Serves one window of the stream list out of the cluster picture.
     *
     * The rows are projected by key before anything narrows them, so a window that asked for no
     * ordering gets the default one and a window that asked for another keeps that order inside
     * its ties.
     *
     * @param TableQueryDTO $query Window query (search, filters, sort, size, address)
     * @return TableSnapshotDTO Window snapshot with raw rows and the total count
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = $this->narrow($this->collectRows(), $query);

        // Only the order is rewritten here - growth sorts by the number the row carries beside it -
        // and the search travels on with the fields it was scoped to.
        $ordering = new TableQueryDTO(
            search: $query->search,
            sort: self::orderingSort($query->sort),
            limit: $query->limit,
            filter: $query->filter,
            anchor: $query->anchor,
            anchorDirection: $query->anchorDirection,
            pageIndex: $query->pageIndex,
            searchableFields: $query->searchableFields,
        );

        return $this->filterInMemory($rows, $ordering);
    }

    /**
     * Configures the row shape used by the log-keys table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosLogKeysTableRow::class);
    }

    /**
     * Projects every node's streams into rows, ordered by key and then by node.
     *
     * @return list<array<string, mixed>> Row payloads across the whole cluster
     */
    private function collectRows(): array
    {
        $index = ClusterLogIndexMirror::index();
        if ($index === null) {
            return [];
        }

        $rows = [];
        foreach ($index->nodes() as $slot) {
            foreach ($this->rowsOfNode($slot) as $row) {
                $rows[] = $row;
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string) $a[HilosLogKeysTableRow::key], (string) $b[HilosLogKeysTableRow::key])
                ?: strcmp((string) $a[HilosLogKeysTableRow::node], (string) $b[HilosLogKeysTableRow::node]),
        );

        return $rows;
    }

    /**
     * Projects one node's streams into rows, leaving the daemon's own class out of the list.
     *
     * @param ClusterLogNodeSlot $slot The node's slot in the cluster picture
     * @return list<array<string, mixed>> Row payloads of this node
     */
    private function rowsOfNode(ClusterLogNodeSlot $slot): array
    {
        $growth = $slot->index->growthBytesPerDay;

        $rows = [];
        foreach ($slot->index->keys as $summary) {
            if ($summary->class === LogKeySummary::CLASS_DAEMON) {
                continue;
            }

            $growthPerDay = $growth[$summary->key] ?? null;
            $rows[] = [
                HilosLogKeysTableRow::rowKey => self::rowKey($slot->nodeId, $summary->key),
                HilosLogKeysTableRow::key => $summary->key,
                HilosLogKeysTableRow::node => $slot->nodeId,
                HilosLogKeysTableRow::streamClass => $summary->class,
                HilosLogKeysTableRow::live => $summary->live,
                HilosLogKeysTableRow::batchCount => count($summary->batchTimestamps),
                // The newest batch, for the stream that is only in the archive: the button into
                // the viewer opens it there, and on the live file there would be nothing to read.
                HilosLogKeysTableRow::lastBatchAt => $summary->batchTimestamps === []
                    ? null
                    : max($summary->batchTimestamps),
                // The live file and every archived occurrence together: what the stream costs.
                HilosLogKeysTableRow::bytes => $summary->totalBytes,
                HilosLogKeysTableRow::growthPerDay => $growthPerDay,
                HilosLogKeysTableRow::growthSort => $growthPerDay ?? self::GROWTH_UNKNOWN,
            ];
        }

        return $rows;
    }

    /**
     * Applies the node filter and the class filter this screen answers to.
     *
     * @param list<array<string, mixed>> $rows Rows of the whole cluster
     * @param TableQueryDTO $query Window query
     * @return list<array<string, mixed>> Rows the window asked for
     */
    private function narrow(array $rows, TableQueryDTO $query): array
    {
        $node = self::filterString($query, self::FILTER_NODE);
        if ($node !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row[HilosLogKeysTableRow::node] === $node,
            ));
        }

        // Any other value narrows nothing, the way an unknown state does on the rotation history:
        // a name this table has no class for is a mistake to ignore, not a window to empty.
        $class = self::filterString($query, self::FILTER_CLASS);
        if ($class === LogKeySummary::CLASS_AGENT || $class === LogKeySummary::CLASS_WORKER) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row[HilosLogKeysTableRow::streamClass] === $class,
            ));
        }

        return $rows;
    }


    /**
     * Reads one string filter value from the open filter map.
     *
     * @param TableQueryDTO $query Window query
     * @param string $key Filter key
     * @return ?string Trimmed value, or null when the window filters on nothing here
     */
    private static function filterString(TableQueryDTO $query, string $key): ?string
    {
        $value = $query->filter[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Turns the window's ordering into the one the row payloads are ordered by.
     *
     * {@see TableSortWhitelist} resolves a wire field into the payload key {@see sortableFields()}
     * allows it to reach and hands it along as each component's column, while
     * {@see InMemoryTableFilter} orders by a component's FIELD — a name it looks up as an array key
     * of the row. The two meet here: for four of the five columns the swap changes nothing, and for
     * the growth it is what puts the unknown at the bottom instead of the top.
     *
     * @param ?TableSortOrderDTO $order Order the window asked for, already through the whitelist
     * @return ?TableSortOrderDTO Order over the payload keys, or null when the window asked for none
     */
    private static function orderingSort(?TableSortOrderDTO $order): ?TableSortOrderDTO
    {
        if ($order === null) {
            return null;
        }

        return $order->withComponents(array_map(
            static fn(TableSortDTO $component): TableSortDTO
                => new TableSortDTO($component->column ?? $component->field, $component->direction),
            $order->components,
        ));
    }

    /**
     * Builds the row key that tells one stream name on two nodes apart.
     *
     * @param ?string $nodeId Node the file lives on, null in a single-node installation
     * @param string $key File basename of the stream
     * @return string Stable row key
     */
    private static function rowKey(?string $nodeId, string $key): string
    {
        return ($nodeId ?? self::ROW_KEY_NODELESS) . self::ROW_KEY_SEPARATOR . $key;
    }
}
