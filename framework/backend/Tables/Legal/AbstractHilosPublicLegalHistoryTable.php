<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidFormatException;
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
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalDocument;

/** A public window over the declared history of a document fixed by its table class. */
abstract class AbstractHilosPublicLegalHistoryTable extends TableDefinition implements ViewportTable
{
    protected const LegalDocument DOCUMENT = LegalDocument::TERMS;

    private const string ROW_SLOT = 'revision';

    /** @return int Revisions in the initial window */
    public function windowSize(): int
    {
        return 25;
    }

    /**
     * @param SourceChange $change Source fact, unrelated to this immutable catalog projection
     * @return ?TableRowMutationDTO No live catalog mutation
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Published revision row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When a row has no identity
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Every field is public catalog metadata */
    public function wireFields(): array
    {
        return [
            HilosPublicLegalHistoryTableRow::rowKey => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::revisionId => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::publishedOn => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::effectiveOn => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::significance => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::setVersion => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::deviationCount => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::origin => WireField::notPersonal(),
            HilosPublicLegalHistoryTableRow::previousSetVersion => WireField::notPersonal(),
        ];
    }

    /** Configures the public typed row. */
    protected function init(): void
    {
        $this->setRowClass(HilosPublicLegalHistoryTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Standard window coordinates
     * @return TableSnapshotDTO Newest declarations first, empty for an absent or faulty catalog
     * @throws InvalidFormatException When the public projection has a malformed metadata row
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        try {
            foreach (LegalAgreementsProjector::revisions()['documents'] as $document) {
                if ($document['document'] !== static::DOCUMENT->value) {
                    continue;
                }
                foreach (array_reverse($document['revisions']) as $revision) {
                    $rows[] = HilosPublicLegalHistoryTableRow::fromArray([
                        HilosPublicLegalHistoryTableRow::rowKey => $revision[HilosPublicLegalHistoryTableRow::revisionId],
                        ...$revision,
                    ])->toArray();
                }
            }
        } catch (LegalException) {
            $rows = [];
        }

        return $this->filterInMemory($rows, $query);
    }
}
