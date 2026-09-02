<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogWorkerSummary;
use Hilos\Pages\Logs\AbstractHilosLogsWorkersPage;

/**
 * Framework log-workers table: the worker streams of every node, one row per stream on a node (HIL-386).
 *
 * Rows are built out of {@see ClusterLogIndexMirror} and out of nothing else — the picture the
 * nodes reported, held in the page worker's memory. It walks no directory: a walk here would block
 * the worker on file I/O and could only ever see the node it happens to run on, which is one
 * machine's store shown as though it were the installation's.
 *
 * The rows come from the mirror's `workers` branch and not from its `keys` one, even though a
 * worker stream appears in both. {@see LogKeySummary} folds both worker prefixes into a single
 * `worker` class, which is exactly the distinction this screen exists to show; only
 * {@see LogWorkerSummary} carries it.
 *
 * A {@see ViewportTable}, so a window is served whole rather than assembled from row deltas: the
 * mirror is neither a DB nor a runtime source, raises no {@see SourceChange}, and there is
 * therefore nothing a delta could be built from. The page re-sends the window when the mirror's
 * fingerprint moves ({@see AbstractHilosLogsWorkersPage::onAgentTick()}).
 */
final class HilosLogWorkersTable extends TableDefinition implements ViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosLogWorkers';

    /** Filter-map key: narrow the streams to one cluster node (absent in a single-node installation). */
    public const string FILTER_NODE = 'node';

    /** Filter-map key: narrow the streams to one worker kind; only {@see self::TYPE_MONOPOLISTIC} narrows. */
    public const string FILTER_TYPE = 'type';

    /** Worker kind: the stream of the worker that holds work no two hands may do; also the one value {@see self::FILTER_TYPE} takes. */
    public const string TYPE_MONOPOLISTIC = 'monopolistic';

    /** Worker kind: the stream of an ordinary worker, of which there may be any number. */
    public const string TYPE_REGULAR = 'regular';

    /** Wire slot the row payload rides under; must match the frontend stream slot. */
    private const string ROW_SLOT = 'stream';

    /** Separator between the node and the log key inside a row key. */
    private const string ROW_KEY_SEPARATOR = ':';

    /** Stands in for the node in a row key when the installation has no node id at all. */
    private const string ROW_KEY_NODELESS = '-';

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
     * Serializes one worker-stream row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Log-workers table row from this table's window
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
     * All four order by themselves, so the map is an identity one and the ordering handed to
     * {@see InMemoryTableFilter} is the window's own. The key table needs a swap here only for its
     * daily growth, whose drawn value is null while the measuring window fills; this screen draws
     * no growth column and has no null among the four.
     *
     * @return array<string, string> Wire row fields mapped to the payload keys they order by
     */
    protected function sortableFields(): array
    {
        return [
            HilosLogWorkersTableRow::key => HilosLogWorkersTableRow::key,
            HilosLogWorkersTableRow::node => HilosLogWorkersTableRow::node,
            HilosLogWorkersTableRow::batchCount => HilosLogWorkersTableRow::batchCount,
            HilosLogWorkersTableRow::bytes => HilosLogWorkersTableRow::bytes,
        ];
    }

    /**
     * Serves one window of the worker-stream list out of the cluster picture.
     *
     * The rows are projected by key before anything narrows them, so a window that asked for no
     * ordering gets the default one and a window that asked for another keeps that order inside
     * its ties.
     *
     * @param TableQueryDTO $query Window query (search, filters, sort, offset, limit)
     * @return TableSnapshotDTO Window snapshot with raw rows and the total count
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = $this->narrow($this->collectRows(), $query);

        // The search is spent above, on the two names this screen searches by; handing it on
        // would search the byte weights and the batch counts as well.
        $ordering = new TableQueryDTO(
            sort: $query->sort,
            offset: $query->offset,
            limit: $query->limit,
            filter: $query->filter,
        );

        return InMemoryTableFilter::apply($rows, $ordering);
    }

    /**
     * Configures the row shape used by the log-workers table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosLogWorkersTableRow::class);
    }

    /**
     * Projects every node's worker streams into rows, ordered by key and then by node.
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
            static fn(array $a, array $b): int => strcmp((string) $a[HilosLogWorkersTableRow::key], (string) $b[HilosLogWorkersTableRow::key])
                ?: strcmp((string) $a[HilosLogWorkersTableRow::node], (string) $b[HilosLogWorkersTableRow::node]),
        );

        return $rows;
    }

    /**
     * Projects one node's worker streams into rows.
     *
     * @param ClusterLogNodeSlot $slot The node's slot in the cluster picture
     * @return list<array<string, mixed>> Row payloads of this node
     */
    private function rowsOfNode(ClusterLogNodeSlot $slot): array
    {
        $rows = [];
        foreach ($slot->index->workers as $summary) {
            $rows[] = [
                HilosLogWorkersTableRow::rowKey => self::rowKey($slot->nodeId, $summary->key),
                HilosLogWorkersTableRow::key => $summary->key,
                HilosLogWorkersTableRow::node => $slot->nodeId,
                HilosLogWorkersTableRow::type => $summary->monopolistic
                    ? self::TYPE_MONOPOLISTIC
                    : self::TYPE_REGULAR,
                HilosLogWorkersTableRow::live => $summary->live,
                HilosLogWorkersTableRow::batchCount => count($summary->batchTimestamps),
                // The newest batch, for the stream that is only in the archive: the button into
                // the viewer opens it there, and on the live file there would be nothing to read.
                HilosLogWorkersTableRow::lastBatchAt => $summary->batchTimestamps === []
                    ? null
                    : max($summary->batchTimestamps),
                // The live file and every archived occurrence together: what the stream costs.
                HilosLogWorkersTableRow::bytes => $summary->totalBytes,
            ];
        }

        return $rows;
    }

    /**
     * Applies the node filter, the type filter and the search this screen answers to.
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
                static fn(array $row): bool => $row[HilosLogWorkersTableRow::node] === $node,
            ));
        }

        // Only the monopolistic value narrows, the way an unknown state does on the rotation
        // history: the panel offers two buttons, and any other value is "all" rather than an
        // emptied window. A third kind can be added later without changing this language.
        if (self::filterString($query, self::FILTER_TYPE) === self::TYPE_MONOPOLISTIC) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row[HilosLogWorkersTableRow::type] === self::TYPE_MONOPOLISTIC,
            ));
        }

        $search = $query->search === null ? '' : trim($query->search);
        if ($search === '') {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => self::matches($row, $needle),
        ));
    }

    /**
     * Whether one row answers the search term.
     *
     * Two fields and not the whole payload: the key, which is the file's name, and the node. The
     * weight and the batch count are numbers an operator reads, not names they search by, and
     * searching them would make every short term match nearly every row.
     *
     * @param array<string, mixed> $row Row payload
     * @param string $needle Search term, already lowercased
     * @return bool True when the row carries the term
     */
    private static function matches(array $row, string $needle): bool
    {
        $haystacks = [(string) $row[HilosLogWorkersTableRow::key]];

        // A single-node installation has no node name at all, which is a field to skip and not an
        // empty one to search.
        $node = $row[HilosLogWorkersTableRow::node];
        if (is_string($node)) {
            $haystacks[] = $node;
        }

        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
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
