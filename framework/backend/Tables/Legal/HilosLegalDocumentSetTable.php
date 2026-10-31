<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\AdminViewMode\WireField;
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
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Pages\Legal\DTO\HilosLegalDocumentPageSubscribeParams;

/** Read-only clauses of the current revision's adopted standard set. */
final class HilosLegalDocumentSetTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosLegalDocumentSet';

    /** Document key from the page route. */
    public const string FILTER_DOCUMENT = 'document';

    private const string ROW_SLOT = 'clause';

    /** @return int Rows carried by the initial window */
    public function windowSize(): int
    {
        return 25;
    }

    /** @return TableSortOrderDTO Adopted clause order */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLegalDocumentSetTableRow::position, TableConstants::ORDER_ASC));
    }

    /**
     * @param PageRouteParams $params Document route
     * @return array<string, mixed> Required document scope
     * @throws MissingPageRouteParamException When the document key is absent
     */
    public function defaultFilter(PageRouteParams $params): array
    {
        return [self::FILTER_DOCUMENT => HilosLegalDocumentPageSubscribeParams::fromPageRouteParams($params)->documentKey];
    }

    /**
     * @param SourceChange $change Source fact; this immutable catalog has no per-row mutations
     * @return ?TableRowMutationDTO No per-row delta
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

    /** @return array<string, WireField> Catalog metadata without personal data */
    public function wireFields(): array
    {
        return [
            HilosLegalDocumentSetTableRow::rowKey => WireField::notPersonal(),
            HilosLegalDocumentSetTableRow::clauseKey => WireField::notPersonal(),
            HilosLegalDocumentSetTableRow::statement => WireField::notPersonal(),
            HilosLegalDocumentSetTableRow::position => WireField::notPersonal(),
        ];
    }

    /**
     * @param TableQueryDTO $query Window descriptor
     * @return TableSnapshotDTO Catalog rows, or an empty window when the page reports a catalog refusal
     * @throws TableSearchNotSupportedException When the query asks for unsupported search
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
        $this->setRowClass(HilosLegalDocumentSetTableRow::class);
    }

    /** @return array<string, string> Declared catalog search fields */
    protected function searchableFields(): array
    {
        return [
            HilosLegalDocumentSetTableRow::clauseKey => HilosLegalDocumentSetTableRow::clauseKey,
            HilosLegalDocumentSetTableRow::statement => HilosLegalDocumentSetTableRow::statement,
        ];
    }

    /** @return array<string, string> Declared scalar orders, including the opening clause order */
    protected function sortableFields(): array
    {
        return [
            HilosLegalDocumentSetTableRow::clauseKey => HilosLegalDocumentSetTableRow::clauseKey,
            HilosLegalDocumentSetTableRow::statement => HilosLegalDocumentSetTableRow::statement,
            HilosLegalDocumentSetTableRow::position => HilosLegalDocumentSetTableRow::position,
        ];
    }

    /**
     * @param TableQueryDTO $query Document scope
     * @return list<array<string, mixed>> Rows in adopted clause order
     * @throws LegalException When the catalog or its text cannot be read
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $key = $query->filter[self::FILTER_DOCUMENT] ?? null;
        $document = is_string($key) ? LegalDocument::tryFrom($key) : null;
        if ($document === null || !in_array($document, LegalCatalogResolver::documents(), true)) {
            return [];
        }
        $revision = LegalCatalogResolver::latestRevision($document);
        $set = StandardSetCatalog::set($document, $revision->setVersion);
        $rows = [];
        foreach ($set->clauses as $position => $clause) {
            $rows[] = new HilosLegalDocumentSetTableRow($clause->key, $clause->key, $clause->statement, $position)->toArray();
        }

        return $rows;
    }
}
