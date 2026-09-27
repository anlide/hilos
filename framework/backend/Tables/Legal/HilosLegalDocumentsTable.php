<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\DatabaseException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Legal\LegalWire;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;

/**
 * Legal catalog projection; the audience refreshes whole windows when its histograms change.
 */
final class HilosLegalDocumentsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosLegalDocuments';

    private const string ROW_SLOT = 'document';

    /** @return int Rows carried by the initial window */
    public function windowSize(): int
    {
        return 25;
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

        return $this->filterInMemory($rows, $query);
    }

    /** Configures the typed row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosLegalDocumentTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Window filters
     * @return list<array<string, mixed>> Wire rows in catalog order
     * @throws LegalException When the catalog is invalid
     * @throws DatabaseException When acceptance histograms cannot be read
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $rows = [];
        foreach (LegalAdminAudience::tallies() as $tally) {
            $rows[] = new HilosLegalDocumentTableRow(
                rowKey: $tally->document,
                declared: $tally->declared,
                revision: $tally->declared
                    ? LegalWire::revision(LegalCatalogResolver::latestRevision(LegalDocument::from($tally->document)))
                    : null,
                covered: $tally->covered,
                window: $tally->window,
                lapsed: $tally->lapsed,
            )->toArray();
        }

        return $rows;
    }
}
