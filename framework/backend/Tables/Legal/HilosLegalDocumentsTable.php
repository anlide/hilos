<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
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

/** Legal catalog projection; coalesced histogram changes use the ordinary viewport mutation path. */
final class HilosLegalDocumentsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosLegalDocuments';

    private const string ROW_SLOT = 'document';

    /** @return int Rows carried by the initial window */
    public function windowSize(): int
    {
        return 25;
    }

    /** @return TableSortOrderDTO Newest declared publication first */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLegalDocumentTableRow::publishedOn, TableConstants::ORDER_DESC));
    }

    /**
     * Projects the full set independently of a viewer's search and window.
     *
     * @param list<string> $previousRowKeys Keys known to the current viewport
     * @return list<TableRowMutationDTO> Current rows and deletions of known keys absent from the full set
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     */
    public function aggregateMutations(array $previousRowKeys): array
    {
        $mutations = [];
        $currentRowKeys = [];
        foreach ($this->collectRows() as $row) {
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
     * @param string|int $rowKey Document key to check in the complete projection
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the document belongs to the searched set, independently of its window
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     * @throws TableSearchNotSupportedException When the query asks for unsupported search
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory(
            array_map(static fn (HilosLegalDocumentTableRow $row): array => $row->toArray(), $this->collectRows()),
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
     * the document key may also originate from recorded acceptances, so it is declared as a column.
     *
     * @return array<string, WireField> Field origins for the admin view mode
     */
    public function wireFields(): array
    {
        return [
            HilosLegalDocumentTableRow::rowKey => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::document,
            ),
            HilosLegalDocumentTableRow::documentLabel => WireField::column(
                HilosDbContext::legalAcceptances,
                ObjectLegalAcceptance::document,
            ),
            HilosLegalDocumentTableRow::publishedOn => WireField::notPersonal(),
            HilosLegalDocumentTableRow::declared => WireField::notPersonal(),
            HilosLegalDocumentTableRow::revision => WireField::notPersonal(),
            HilosLegalDocumentTableRow::covered => WireField::notPersonal(),
            HilosLegalDocumentTableRow::window => WireField::notPersonal(),
            HilosLegalDocumentTableRow::lapsed => WireField::notPersonal(),
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
            $rows = $this->collectRows();
        } catch (LegalException) {
            $rows = [];
        }

        return $this->filterInMemory(
            array_map(static fn (HilosLegalDocumentTableRow $row): array => $row->toArray(), $rows),
            $query,
        );
    }

    /** Configures the typed row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosLegalDocumentTableRow::class);
    }

    /** @return array<string, string> Search by document label or stored key */
    protected function searchableFields(): array
    {
        return [
            HilosLegalDocumentTableRow::documentLabel => HilosLegalDocumentTableRow::documentLabel,
            HilosLegalDocumentTableRow::rowKey => HilosLegalDocumentTableRow::rowKey,
        ];
    }

    /** @return array<string, string> Single-field orders over document metadata and coverage counts */
    protected function sortableFields(): array
    {
        return [
            HilosLegalDocumentTableRow::documentLabel => HilosLegalDocumentTableRow::documentLabel,
            HilosLegalDocumentTableRow::publishedOn => HilosLegalDocumentTableRow::publishedOn,
            HilosLegalDocumentTableRow::covered => HilosLegalDocumentTableRow::covered,
            HilosLegalDocumentTableRow::window => HilosLegalDocumentTableRow::window,
            HilosLegalDocumentTableRow::lapsed => HilosLegalDocumentTableRow::lapsed,
        ];
    }

    /** @return bool Keep recorded-only documents after known publication dates in either direction */
    protected function inMemoryNullsLast(): bool
    {
        return true;
    }

    /**
     * @return list<HilosLegalDocumentTableRow> Complete projection in tally order, including recorded-only keys
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     */
    private function collectRows(): array
    {
        $rows = [];
        foreach (LegalAdminAudience::tallies() as $tally) {
            $revision = $tally->declared ? LegalCatalogResolver::latestRevision(LegalDocument::from($tally->document)) : null;
            $rows[] = new HilosLegalDocumentTableRow(
                rowKey: $tally->document,
                declared: $tally->declared,
                revision: $revision === null ? null : LegalWire::revision($revision),
                covered: $tally->covered,
                window: $tally->window,
                lapsed: $tally->lapsed,
                documentLabel: LegalWire::documentLabel($tally->document),
                publishedOn: $revision?->publishedOn,
            );
        }

        return $rows;
    }
}
