<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportStateSignalData;
use Hilos\Utils\Helpers\TimeHelper;

/** The same export node for the page response of the acceptances page and for the state signal after it. */
final class LegalAcceptancesExportProjector
{
    public const string SECTION = LegalAcceptancesExportStateSignalData::legalAcceptancesExport;

    /**
     * @param ?LegalAcceptanceExport $export The administrator's order, if any
     * @return ?array{
     *     state: string,
     *     document: ?string,
     *     revisionId: ?string,
     *     search: ?string,
     *     requestedAt: int,
     *     finishedAt: ?int,
     *     expiresAt: ?int,
     *     sizeBytes: ?int,
     *     records: ?int,
     * } Portable state node
     */
    public static function nodeFor(?LegalAcceptanceExport $export): ?array
    {
        return $export === null ? null : [
            LegalAcceptancesExportStateSignalData::state => $export->state,
            LegalAcceptancesExportStateSignalData::document => $export->document,
            LegalAcceptancesExportStateSignalData::revisionId => $export->revisionId,
            LegalAcceptancesExportStateSignalData::search => $export->search,
            LegalAcceptancesExportStateSignalData::requestedAt => TimeHelper::sqlToMs($export->requestedAt),
            LegalAcceptancesExportStateSignalData::finishedAt => $export->finishedAt === null ? null : TimeHelper::sqlToMs($export->finishedAt),
            LegalAcceptancesExportStateSignalData::expiresAt => $export->expiresAt === null ? null : TimeHelper::sqlToMs($export->expiresAt),
            LegalAcceptancesExportStateSignalData::sizeBytes => $export->sizeBytes,
            LegalAcceptancesExportStateSignalData::records => $export->records,
        ];
    }
}
