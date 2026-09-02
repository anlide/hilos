<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use DateTimeImmutable;
use Hilos\Constants\LogRotationConstants;
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
use Hilos\Log\LogArchiveRetentionPolicy;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\LogSettingsResolver;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;

/**
 * Framework log-rotations table: the archived rotation batches of every node (HIL-387).
 *
 * Rows are built out of {@see ClusterLogIndexMirror} and out of nothing else — the picture the
 * nodes reported, held in the page worker's memory. It walks no directory: a walk here would
 * block the worker on file I/O and could only ever see the node it happens to run on, which is
 * one machine's archive shown as though it were the installation's.
 *
 * A {@see ViewportTable}, so a window is served whole rather than assembled from row deltas:
 * the mirror is neither a DB nor a runtime source, raises no {@see SourceChange}, and there is
 * therefore nothing a delta could be built from. The page re-sends the window when the mirror's
 * fingerprint moves ({@see AbstractHilosLogsRotationsPage::onAgentTick()}).
 *
 * The retention verdict is judged HERE and per node. Judged here, because no durable per-batch
 * state exists yet — HIL-483 introduces it and this screen then draws what arrives instead of
 * deciding it. Per node, because {@see LogArchiveRetentionPolicy::$keepBatches} means "the newest
 * N of THIS archive": one list across the cluster would spend the whole protection on N batches
 * total and send a neighbour's freshest batch to the recommended pile.
 */
final class HilosLogRotationsTable extends TableDefinition implements ViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosLogRotations';

    /** Filter-map key: narrow the history to one cluster node (absent in a single-node installation). */
    public const string FILTER_NODE = 'node';

    /** Filter-map key: narrow the history to one retention state; only {@see self::STATE_DUE} narrows. */
    public const string FILTER_STATE = 'state';

    /** Retention state: the batch is inside what the policy protects. */
    public const string STATE_KEPT = 'kept';

    /** Retention state: the policy recommends carrying the batch off; also the one value {@see self::FILTER_STATE} takes. */
    public const string STATE_DUE = 'due';

    /**
     * Retention state: an operator has confirmed the batch was carried off.
     *
     * SCAFFOLD: never assigned here — the confirmation is a durable fact this leaf has no source
     * for, and HIL-483 is what starts writing it. The value is named now so that the screen, the
     * wire and the frontend badge already know the third state by the time it can happen.
     */
    public const string STATE_TAKEN = 'taken';

    /** Wire slot the row payload rides under; must match the frontend batch slot. */
    private const string ROW_SLOT = 'batch';

    /** Separator between the node and the batch timestamp inside a row key. */
    private const string ROW_KEY_SEPARATOR = ':';

    /** Stands in for the node in a row key when the installation has no node id at all. */
    private const string ROW_KEY_NODELESS = '-';

    /**
     * @var ?LogSettingsResolver The reader the retention policy is asked for, one per process
     *
     * Static and kept, the way {@see LogStoreAgent} keeps its own: the resolver
     * remembers the last outcome so an unchanged fault stops repeating itself into the journal,
     * and a fresh instance per window would forget that with every keystroke in the search box.
     */
    private static ?LogSettingsResolver $settingsResolver = null;

    /**
     * The rotation history has no live per-row source; a window refresh is a re-projection.
     *
     * @param SourceChange $change Source change (ignored)
     * @return ?TableRowMutationDTO Always null — the mirror raises no source events
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Serializes one batch row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Rotation table row from this table's window
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
     * Declares the history's sortable columns, which here are the row payload keys themselves.
     *
     * The rows are ordered in PHP by {@see InMemoryTableFilter}, where a field name is an array
     * key and no identifier is built out of it; the map is still declared, because it is the gate
     * that keeps a window from ordering by a name this table does not sort by.
     *
     * @return array<string, string> Wire row fields mapped to the payload keys they order by
     */
    protected function sortableFields(): array
    {
        return [
            HilosLogRotationsTableRow::batchAt => HilosLogRotationsTableRow::batchAt,
            HilosLogRotationsTableRow::node => HilosLogRotationsTableRow::node,
            HilosLogRotationsTableRow::bytes => HilosLogRotationsTableRow::bytes,
        ];
    }

    /**
     * Serves one window of the rotation history out of the cluster picture.
     *
     * The rows are projected newest-first before anything narrows them, so a window that asked
     * for no ordering gets the default one and a window that asked for another keeps that order
     * inside its ties.
     *
     * @param TableQueryDTO $query Window query (search, filters, sort, offset, limit)
     * @return TableSnapshotDTO Window snapshot with raw rows and the total count
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = $this->narrow($this->collectRows(), $query);

        // The search is spent above, on the two fields this history searches by; handing it on
        // would search the byte weights and the file counts as well.
        $ordering = new TableQueryDTO(
            sort: $query->sort,
            offset: $query->offset,
            limit: $query->limit,
            filter: $query->filter,
        );

        return InMemoryTableFilter::apply($rows, $ordering);
    }

    /**
     * Configures the row shape used by the log-rotations table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosLogRotationsTableRow::class);
    }

    /**
     * Projects every node's archive into rows, newest batch first.
     *
     * @return list<array<string, mixed>> Row payloads across the whole cluster
     */
    private function collectRows(): array
    {
        $index = ClusterLogIndexMirror::index();
        if ($index === null) {
            return [];
        }

        // Read once for the whole window, because the rule is one for the whole cluster: what is
        // per node is the archive the rule is applied TO, not the rule itself.
        $policy = self::settingsResolver()->retentionPolicy();
        $now = time();
        $rows = [];
        foreach ($index->nodes() as $slot) {
            foreach ($this->rowsOfNode($slot, $policy, $now) as $row) {
                $rows[] = $row;
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => $b[HilosLogRotationsTableRow::batchAt] <=> $a[HilosLogRotationsTableRow::batchAt]
                ?: strcmp((string) $a[HilosLogRotationsTableRow::node], (string) $b[HilosLogRotationsTableRow::node]),
        );

        return $rows;
    }

    /**
     * Projects one node's archive into rows, with the retention verdict judged over that archive alone.
     *
     * @param ClusterLogNodeSlot $slot The node's slot in the cluster picture
     * @param LogArchiveRetentionPolicy $policy The rule in force, one for the whole cluster
     * @param int $now Instant the batch ages are measured against, in Unix seconds
     * @return list<array<string, mixed>> Row payloads of this node
     */
    private function rowsOfNode(ClusterLogNodeSlot $slot, LogArchiveRetentionPolicy $policy, int $now): array
    {
        $batches = $slot->index->batches;
        $timestamps = array_map(static fn(LogBatchSummary $batch): int => $batch->timestamp, $batches);
        $due = array_flip($policy->selectEvictionCandidates($timestamps, $now));

        $rows = [];
        foreach ($batches as $batch) {
            $rows[] = [
                HilosLogRotationsTableRow::rowKey => self::rowKey($slot->nodeId, $batch->timestamp),
                HilosLogRotationsTableRow::batchAt => $batch->timestamp,
                HilosLogRotationsTableRow::node => $slot->nodeId,
                HilosLogRotationsTableRow::path => self::archivePath($batch->timestamp),
                HilosLogRotationsTableRow::agentFileCount => $batch->agentFileCount,
                HilosLogRotationsTableRow::workerFileCount => $batch->workerFileCount,
                HilosLogRotationsTableRow::workerMonopolisticFileCount => $batch->workerMonopolisticFileCount,
                // Every class of stream, the daemon's own included: this is what the directory costs.
                HilosLogRotationsTableRow::bytes => $batch->agentBytes + $batch->workerBytes
                    + $batch->workerMonopolisticBytes + $batch->daemonBytes,
                HilosLogRotationsTableRow::retentionState => isset($due[$batch->timestamp])
                    ? self::STATE_DUE
                    : self::STATE_KEPT,
            ];
        }

        return $rows;
    }

    /**
     * Applies the node filter, the state filter and the search this history answers to.
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
                static fn(array $row): bool => $row[HilosLogRotationsTableRow::node] === $node,
            ));
        }

        // Any other value narrows nothing, the way an unknown status does on the delivery journal:
        // a name this table has no state for is a mistake to ignore, not a window to empty.
        if (self::filterString($query, self::FILTER_STATE) === self::STATE_DUE) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row[HilosLogRotationsTableRow::retentionState] === self::STATE_DUE,
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
     * Two fields and not the whole payload: the archive directory, whose name IS the batch date in
     * the layout rotation writes (`archive/Y-m-d-H-i-s/`), and the node. The weights and the file
     * counts are numbers an operator reads, not names they search by, and searching them would
     * make every short term match nearly every row.
     *
     * @param array<string, mixed> $row Row payload
     * @param string $needle Search term, already lowercased
     * @return bool True when the row carries the term
     */
    private static function matches(array $row, string $needle): bool
    {
        $haystacks = [(string) $row[HilosLogRotationsTableRow::path]];

        // A single-node installation has no node name at all, which is a field to skip and not an
        // empty one to search.
        $node = $row[HilosLogRotationsTableRow::node];
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
     * Builds the row key that tells one rotation moment on two nodes apart.
     *
     * @param ?string $nodeId Node holding the batch, null in a single-node installation
     * @param int $timestamp Unix timestamp of the batch
     * @return string Stable row key
     */
    private static function rowKey(?string $nodeId, int $timestamp): string
    {
        return ($nodeId ?? self::ROW_KEY_NODELESS) . self::ROW_KEY_SEPARATOR . $timestamp;
    }

    /**
     * Names the archive directory of one batch, relative to that node's log root.
     *
     * The name is re-derived rather than carried: {@see LogBatchSummary} holds the instant and not
     * the directory it was parsed out of. That round-trips exactly while every node of the cluster
     * runs in one timezone — the rotator formats the name in the writing node's, and this formats
     * it back in the page worker's. Nodes set to different timezones would show a path off by the
     * offset; carrying the name itself is the cure, and it belongs to the read model this leaf
     * reads rather than writes.
     *
     * @param int $timestamp Unix timestamp of the batch
     * @return string Directory path, as rotation wrote it
     */
    private static function archivePath(int $timestamp): string
    {
        $name = new DateTimeImmutable()
            ->setTimestamp($timestamp)
            ->format(LogRotationConstants::TIMESTAMP_FORMAT);

        return LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME . '/' . $name . '/';
    }

    /**
     * The process-wide settings reader the retention policy is asked for.
     *
     * @return LogSettingsResolver Reader over the settings, with the environment beneath them
     */
    private static function settingsResolver(): LogSettingsResolver
    {
        // The complaint the resolver raises is not taken here: the rotation agent on each node
        // already writes that line, and a second copy from the page worker says nothing new.
        return self::$settingsResolver ??= new LogSettingsResolver();
    }
}
