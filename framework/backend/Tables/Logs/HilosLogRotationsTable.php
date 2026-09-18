<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use DateTimeImmutable;
use Hilos\Constants\LogRotationConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\TableFacetTally;

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
 * The retention verdict is NOT judged here (HIL-871). The node owns the files, so the node
 * applies the rule to them and reports the answer with its index
 * ({@see NodeLogIndex::$dueBatchTimestamps}); this table reads that list the way it reads a byte
 * count. Two judges over one rule could disagree across the width of a delivery window - the
 * screen offering "how to carry it off" while the node answers that the batch is protected again -
 * and the one that matters is the one holding the directory.
 *
 * Two states are judged here, and each overrules the verdict when it is present. A batch an
 * operator has confirmed carrying off is {@see self::STATE_TAKEN} whatever the rule now says
 * (HIL-483). That one IS a fact about one batch — a marker file inside its directory — so it
 * arrives with the batch instead of being decided from the picture, and it survives an
 * administrator raising the retention period after the fact. Above even that is
 * {@see self::STATE_CARRYING} (HIL-870), which says the batch has left the log root but has not
 * reached the archive: none of the other three is true of it yet, and neither of the row's actions
 * applies to it. Stacking the four states is a reading of what arrived and stays here, because
 * that is what this table is for.
 *
 * A confirmed row also carries the instant its node's pruner may first delete it (HIL-759). Added
 * up here from the two halves the node reports — when it was confirmed, and how long that node
 * protects a confirmed batch — because the sum is a reading rather than a fact, and the screen
 * has to say it out loud to somebody deciding whether they still have time to change their mind.
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
     * It overrules the other two rather than sitting beside them: the confirmation is a durable
     * fact on the node ({@see LogBatchSummary::$takenAt}), and the verdict is a reading of a rule
     * that may have moved since. A batch that came back under protection while its owner was
     * copying it off is still a batch that was carried off.
     */
    public const string STATE_TAKEN = 'taken';

    /**
     * Retention state: the batch is still on its way from staging into the archive (HIL-870).
     *
     * It overrules all three of the states above, the confirmation included, because it is not a
     * verdict about the batch at all but a statement of where the batch IS. Nothing may be said
     * about carrying off a directory that has not arrived, and the node refuses a confirmation for
     * it ({@see LogStoreAgent}); a row that showed "waiting to be carried off" would be offering an
     * action that cannot be taken.
     */
    public const string STATE_CARRYING = 'carrying';

    /** Wire slot the row payload rides under; must match the frontend batch slot. */
    private const string ROW_SLOT = 'batch';

    /** Separator between the node and the batch timestamp inside a row key. */
    private const string ROW_KEY_SEPARATOR = ':';

    /** Stands in for the node in a row key when the installation has no node id at all. */
    private const string ROW_KEY_NODELESS = '-';

    /** Filters whose options this table counts: both of them, the rows being in memory already. */
    private const array FACETED_FILTERS = [self::FILTER_NODE, self::FILTER_STATE];

    /**
     * Declares how many rows the first window of the rotations table carries.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 25;
    }

    /**
     * Declares the order the first window of the rotations table runs in.
     *
     * @return ?TableSortOrderDTO First window ordered by batchAt descending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLogRotationsTableRow::batchAt, TableConstants::ORDER_DESC));
    }

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
     * Counts the options of the node filter and the state filter against the rotation history.
     *
     * Each set is narrowed by {@see narrow()} and searched by the same in-memory filter a window
     * is, so the number beside an option is the total that window would report. The rows are
     * projected once for every set, and nothing is capped: they are in hand, and the count is exact.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search scoped
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts of the
     *     node and state options that were asked about
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        $rows = $this->collectRows();

        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, array_flip(self::FACETED_FILTERS)),
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                $this->filterInMemory($this->narrow($rows, $set), $set)->totalCount,
                true,
            ),
        );
    }

    /**
     * Answers whether one batch row is in the set a window of this table shows.
     *
     * The set is built the way {@see query()} builds it - the cluster picture narrowed by this history's filters - and searched the
     * same way, so the answer cannot drift from the window. It is neither sorted nor cut into a
     * window: a yes or no about one row needs neither.
     *
     * @param string|int $rowKey Row key to look for
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search scoped
     * @return ?bool Whether the row is in the set; this table always knows
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->narrow($this->collectRows(), $query), $rowKey, $query);
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
     * Declares what a rotation row is searched by: the node it happened on and the path it wrote.
     *
     * The file counts and the byte weight are numbers an operator reads and nobody types part of,
     * so they stay out: a short term matching them would match nearly every row.
     *
     * @return array<string, string> Searched fields mapped to themselves, these rows being searched in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosLogRotationsTableRow::node => HilosLogRotationsTableRow::node,
            HilosLogRotationsTableRow::path => HilosLogRotationsTableRow::path,
        ];
    }

    /**
     * Serves one window of the rotation history out of the cluster picture.
     *
     * The rows are projected newest-first before anything narrows them, so a window that asked
     * for no ordering gets the default one and a window that asked for another keeps that order
     * inside its ties.
     *
     * @param TableQueryDTO $query Window query (search, filters, sort, size, address)
     * @return TableSnapshotDTO Window snapshot with raw rows and the total count
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = $this->narrow($this->collectRows(), $query);

        return $this->filterInMemory($rows, $query);
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

        $rows = [];
        foreach ($index->nodes() as $slot) {
            foreach ($this->rowsOfNode($slot) as $row) {
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
     * Projects one node's archive into rows, with the retention verdict that node reported.
     *
     * @param ClusterLogNodeSlot $slot The node's slot in the cluster picture
     * @return list<array<string, mixed>> Row payloads of this node
     */
    private function rowsOfNode(ClusterLogNodeSlot $slot): array
    {
        $due = array_flip($slot->index->dueBatchTimestamps);

        $rows = [];
        foreach ($slot->index->batches as $batch) {
            $path = self::batchPath($batch);
            $rows[] = [
                HilosLogRotationsTableRow::rowKey => self::rowKey($slot->nodeId, $batch->timestamp),
                HilosLogRotationsTableRow::batchAt => $batch->timestamp,
                HilosLogRotationsTableRow::node => $slot->nodeId,
                HilosLogRotationsTableRow::path => $path,
                HilosLogRotationsTableRow::absolutePath => self::absolutePath($slot->index->logDirectory, $path),
                HilosLogRotationsTableRow::daemonFileCount => $batch->daemonFileCount,
                HilosLogRotationsTableRow::agentFileCount => $batch->agentFileCount,
                HilosLogRotationsTableRow::workerFileCount => $batch->workerFileCount,
                HilosLogRotationsTableRow::workerMonopolisticFileCount => $batch->workerMonopolisticFileCount,
                // The same four classes the counts above name, so the weight is what those files cost.
                HilosLogRotationsTableRow::bytes => $batch->agentBytes + $batch->workerBytes
                    + $batch->workerMonopolisticBytes + $batch->daemonBytes,
                HilosLogRotationsTableRow::retentionState => self::retentionState($batch, isset($due[$batch->timestamp])),
                HilosLogRotationsTableRow::pruneNotBefore => self::pruneNotBefore(
                    $batch,
                    $slot->index->takeoutUndoWindowSeconds,
                ),
            ];
        }

        return $rows;
    }

    /**
     * Applies the node filter and the state filter this history answers to.
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
     * Names the directory of one batch, relative to that node's log root.
     *
     * The name is re-derived rather than carried: {@see LogBatchSummary} holds the instant and not
     * the directory it was parsed out of. That round-trips exactly while every node of the cluster
     * runs in one timezone — the rotator formats the name in the writing node's, and this formats
     * it back in the page worker's. Nodes set to different timezones would show a path off by the
     * offset; carrying the name itself is the cure, and it belongs to the read model this leaf
     * reads rather than writes.
     *
     * The subtree is the batch's own (HIL-870): a batch still being carried is in `staging/`, and
     * printing the archive address of a directory that is not there yet would send an
     * administrator to an empty path — the one they would reach for precisely when the far volume
     * is down.
     *
     * @param LogBatchSummary $batch Batch as its node reported it
     * @return string Directory path, as rotation wrote it
     */
    private static function batchPath(LogBatchSummary $batch): string
    {
        $name = new DateTimeImmutable()
            ->setTimestamp($batch->timestamp)
            ->format(LogRotationConstants::TIMESTAMP_FORMAT);
        $subdirectory = $batch->carrying
            ? LogRotationConstants::LOG_STAGING_SUBDIR_NAME
            : LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;

        return $subdirectory . '/' . $name . '/';
    }

    /**
     * The state the badge shows: where the batch is first, then the operator's word, then the rule.
     *
     * @param LogBatchSummary $batch Batch as its node reported it
     * @param bool $due Whether the retention rule names this batch among the ones to carry off
     * @return string One of the four retention states
     */
    private static function retentionState(LogBatchSummary $batch, bool $due): string
    {
        // Answered first, ahead of the confirmation that used to be first: the other three states
        // are readings of a batch that is in the archive, and this one says it is not there yet.
        if ($batch->carrying) {
            return self::STATE_CARRYING;
        }
        if ($batch->takenAt !== null) {
            return self::STATE_TAKEN;
        }

        return $due ? self::STATE_DUE : self::STATE_KEPT;
    }

    /**
     * The instant the pruner of the batch's own node may first delete it (HIL-759).
     *
     * Added up here rather than reported by the node, because both halves already travel and the
     * sum is a reading rather than a fact: the confirmation stamp is the node's clock, the window
     * is the node's promise, and a screen that showed either alone would leave the person to do
     * the arithmetic.
     *
     * Null twice over, and both are the same sentence on screen — there is no deadline to name.
     * A batch nobody has confirmed is not on its way anywhere, and a node whose window is zero has
     * said it will not wait, so the earliest the pruner may act is its very next pass.
     *
     * @param LogBatchSummary $batch Batch as its node reported it
     * @param int $windowSeconds Seconds that node protects a confirmed batch for
     * @return ?int Unix instant the pruner may first delete this batch, or null when there is none
     */
    private static function pruneNotBefore(LogBatchSummary $batch, int $windowSeconds): ?int
    {
        if ($batch->takenAt === null || $windowSeconds === 0) {
            return null;
        }

        return $batch->takenAt + $windowSeconds;
    }

    /**
     * Names the batch directory the way an operator has to type it, on the machine holding it.
     *
     * The root comes from the node's own index and from nowhere else: a page worker knows the log
     * directory of the machine it runs on, and printing that one beside a neighbour's batch would
     * hand an administrator an address that exists — on the wrong host.
     *
     * A node that named no log root — an older build reporting an index frame without one — has no
     * address to offer, and that stays null all the way to the screen rather than becoming an empty
     * string somewhere in the middle. Inventing one out of this worker's own root would be worse
     * than silence: it would look exactly like an answer.
     *
     * @param ?string $logDirectory Absolute log root of the node holding the batch, null when unknown
     * @param string $path Archive directory of the batch, relative to that root
     * @return ?string Absolute directory of the batch, or null when the node named no root
     */
    private static function absolutePath(?string $logDirectory, string $path): ?string
    {
        if ($logDirectory === null) {
            return null;
        }

        return rtrim($logDirectory, '/') . '/' . $path;
    }
}
