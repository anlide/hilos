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
use Hilos\Legal\LegalWire;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Pages\Legal\DTO\HilosLegalDocumentPageSubscribeParams;

/** Read-only deviations of the current revision's adopted standard set. */
final class HilosLegalDocumentDeviationsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosLegalDocumentDeviations';

    /** Document key from the page route. */
    public const string FILTER_DOCUMENT = 'document';

    private const string ROW_SLOT = 'deviation';

    /** @return int Rows carried by the initial window */
    public function windowSize(): int
    {
        return 25;
    }

    /** @return TableSortOrderDTO Adopted clause order */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLegalDocumentDeviationTableRow::position, TableConstants::ORDER_ASC));
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
            HilosLegalDocumentDeviationTableRow::rowKey => WireField::notPersonal(),
            HilosLegalDocumentDeviationTableRow::clauseKey => WireField::notPersonal(),
            HilosLegalDocumentDeviationTableRow::standardStatement => WireField::notPersonal(),
            HilosLegalDocumentDeviationTableRow::statement => WireField::notPersonal(),
            HilosLegalDocumentDeviationTableRow::text => WireField::notPersonal(),
            HilosLegalDocumentDeviationTableRow::direction => WireField::notPersonal(),
            HilosLegalDocumentDeviationTableRow::position => WireField::notPersonal(),
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
        $this->setRowClass(HilosLegalDocumentDeviationTableRow::class);
    }

    /** @return array<string, string> Declared catalog search fields */
    protected function searchableFields(): array
    {
        return [
            HilosLegalDocumentDeviationTableRow::statement => HilosLegalDocumentDeviationTableRow::statement,
            HilosLegalDocumentDeviationTableRow::text => HilosLegalDocumentDeviationTableRow::text,
            HilosLegalDocumentDeviationTableRow::clauseKey => HilosLegalDocumentDeviationTableRow::clauseKey,
        ];
    }

    /** @return array<string, string> Declared scalar orders, including the opening clause order */
    protected function sortableFields(): array
    {
        return [
            HilosLegalDocumentDeviationTableRow::direction => HilosLegalDocumentDeviationTableRow::direction,
            HilosLegalDocumentDeviationTableRow::clauseKey => HilosLegalDocumentDeviationTableRow::clauseKey,
            HilosLegalDocumentDeviationTableRow::position => HilosLegalDocumentDeviationTableRow::position,
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
        $deviations = array_column(LegalWire::deviations($revision), null, 'clauseKey');
        $rows = [];
        foreach ($set->clauses as $position => $clause) {
            $deviation = $deviations[$clause->key] ?? null;
            if ($deviation === null) {
                continue;
            }
            $rows[] = new HilosLegalDocumentDeviationTableRow(
                rowKey: $clause->key,
                clauseKey: $clause->key,
                standardStatement: $deviation['standardStatement'],
                statement: $deviation['statement'],
                text: $deviation['text'],
                direction: $deviation['direction'],
                position: $position,
            )->toArray();
        }

        return $rows;
    }
}
