<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\LegalAcceptance as ObjectLegalAcceptance;
use Hilos\Legal\Exception\LegalException;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Legal\LegalWire;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalStandingResolver;

/** Legal revision projection with document-scoped aggregate mutations for ordinary viewport delivery. */
final class HilosLegalRevisionsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosLegalRevisions';

    /** Document key from the page route. */
    public const string FILTER_DOCUMENT = 'document';

    /** Exact revision requested by its detail page, independent of the history window. */
    public const string FILTER_REVISION = 'revision';

    private const string ROW_SLOT = 'revision';

    /** @return int Rows carried by the initial window */
    public function windowSize(): int
    {
        return 25;
    }

    /** @return TableSortOrderDTO Newest declared publication first */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLegalRevisionTableRow::publishedOn, TableConstants::ORDER_DESC));
    }

    /**
     * @param PageRouteParams $params Document route, optionally naming an exact revision
     * @return array<string, mixed> Document scope and exact revision when present
     * @throws MissingPageRouteParamException When the document key is absent
     */
    public function defaultFilter(PageRouteParams $params): array
    {
        $filter = [self::FILTER_DOCUMENT => $params->requireString(HilosPageRouteParams::HILOS_LEGAL_DOCUMENT_KEY)];
        $revision = $params->getString(HilosPageRouteParams::HILOS_LEGAL_REVISION_ID);
        if ($revision !== null) {
            $filter[self::FILTER_REVISION] = $revision;
        }

        return $filter;
    }

    /**
     * Projects the full document scope independently of a viewer's search and window.
     *
     * @param TableQueryDTO $query Document scope, including an exact revision when requested
     * @param list<string> $previousRowKeys Keys known to the current viewport
     * @return list<TableRowMutationDTO> Current rows and deletions of vanished known keys
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     */
    public function aggregateMutations(TableQueryDTO $query, array $previousRowKeys): array
    {
        $mutations = [];
        $currentRowKeys = [];
        foreach ($this->collectRows($query) as $row) {
            $currentRowKeys[$row->rowKey] = true;
            $mutations[] = new TableRowMutationDTO(TableMutationType::Update, $row->rowKey, $row);
        }
        foreach ($previousRowKeys as $rowKey) {
            if (!isset($currentRowKeys[$rowKey])) {
                $mutations[] = new TableRowMutationDTO(TableMutationType::Delete, $rowKey);
            }
        }

        return $mutations;
    }

    /**
     * @param string|int $rowKey Revision key to check within the document
     * @param TableQueryDTO $query Window's document, exact revision and search scope
     * @return ?bool Membership independent of window position
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     * @throws TableSearchNotSupportedException When the query asks for unsupported search
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory(
            array_map(static fn (HilosLegalRevisionTableRow $row): array => $row->toArray(), $this->collectRows($query)),
            $rowKey,
            $this->scopeSearch($query),
        );
    }

    /**
     * @param SourceChange $change Source fact, coalesced by the section audience instead
     * @return ?TableRowMutationDTO No per-row delta for this aggregate
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Row in this table's window
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser envelope
     * @throws TableRowKeyMissingException When the row has no identity
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /**
     * Declares where each field of the row comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * The row projects code catalog definitions and acceptance counters with no personal data;
     * the revision id may also originate from recorded acceptances (undeclared revisions), so it is declared as a column.
     *
     * @return array<string, WireField>
     */
    public function wireFields(): array
    {
        return [
            HilosLegalRevisionTableRow::rowKey => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::revisionId,
            ),
            HilosLegalRevisionTableRow::declared => WireField::notPersonal(),
            HilosLegalRevisionTableRow::revision => WireField::notPersonal(),
            HilosLegalRevisionTableRow::current => WireField::notPersonal(),
            HilosLegalRevisionTableRow::origin => WireField::notPersonal(),
            HilosLegalRevisionTableRow::heldCount => WireField::notPersonal(),
            HilosLegalRevisionTableRow::acceptedCount => WireField::notPersonal(),
            HilosLegalRevisionTableRow::publishedOn => WireField::notPersonal(),
            HilosLegalRevisionTableRow::effectiveOn => WireField::notPersonal(),
        ];
    }

    /**
     * @param TableQueryDTO $query Window descriptor
     * @return TableSnapshotDTO Catalog rows, or an empty window when the page reports a catalog refusal
     * @throws DatabaseException When acceptance histograms cannot be read
     * @throws TableSearchNotSupportedException When the query asks for search
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        try {
            $rows = $this->collectRows($query);
        } catch (LegalException) {
            $rows = [];
        }

        return $this->filterInMemory(
            array_map(static fn (HilosLegalRevisionTableRow $row): array => $row->toArray(), $rows),
            $query,
        );
    }

    /** Configures the typed row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosLegalRevisionTableRow::class);
    }

    /** @return array<string, string> Search by exact revision key or publication date */
    protected function searchableFields(): array
    {
        return [
            HilosLegalRevisionTableRow::rowKey => HilosLegalRevisionTableRow::rowKey,
            HilosLegalRevisionTableRow::publishedOn => HilosLegalRevisionTableRow::publishedOn,
        ];
    }

    /** @return array<string, string> Server orders over calendar metadata and holder counts */
    protected function sortableFields(): array
    {
        return [
            HilosLegalRevisionTableRow::publishedOn => HilosLegalRevisionTableRow::publishedOn,
            HilosLegalRevisionTableRow::effectiveOn => HilosLegalRevisionTableRow::effectiveOn,
            HilosLegalRevisionTableRow::heldCount => HilosLegalRevisionTableRow::heldCount,
        ];
    }

    /** @return bool Keep recorded-only revisions after declared dates in either direction */
    protected function inMemoryNullsLast(): bool
    {
        return true;
    }

    /**
     * @param TableQueryDTO $query Window filters
     * @return list<HilosLegalRevisionTableRow> Complete document projection in catalog order
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $document = $query->filter[self::FILTER_DOCUMENT] ?? null;
        if (!is_string($document)) {
            return [];
        }
        $tally = LegalAdminAudience::tallies()[$document] ?? null;
        if ($tally === null) {
            return [];
        }
        $rows = [];
        if ($tally->declared) {
            $kind = LegalDocument::from($document);
            $current = LegalCatalogResolver::latestRevision($kind);
            foreach (array_reverse(LegalCatalogResolver::revisions($kind)) as $revision) {
                $rows[] = new HilosLegalRevisionTableRow(
                    rowKey: $revision->id,
                    declared: true,
                    revision: LegalWire::revision($revision),
                    current: $revision->id === $current->id,
                    origin: LegalStandingResolver::origin($kind, $revision->id)->value,
                    heldCount: $tally->heldByRevision[$revision->id] ?? 0,
                    acceptedCount: $tally->acceptedByRevision[$revision->id] ?? 0,
                    publishedOn: $revision->publishedOn,
                    effectiveOn: $revision->effectiveOn,
                );
            }
        }
        foreach ($tally->undeclared as $revisionId => $people) {
            $rows[] = new HilosLegalRevisionTableRow(
                (string) $revisionId, false, null, false, null, $people, $people, null, null,
            );
        }
        $requested = $query->filter[self::FILTER_REVISION] ?? null;
        if (is_string($requested)) {
            $rows = array_values(array_filter(
                $rows,
                static fn (HilosLegalRevisionTableRow $row): bool => $row->rowKey === $requested,
            ));
        }

        return $rows;
    }
}
