<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Legal;

use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSetCatalog;

/**
 * ConcertTicketsLegalCatalog - The concert-tickets demo's legal documents.
 *
 * The Terms document follows the framework standard. Current Privacy declares the analytics exception to
 * account erasure.
 */
final class ConcertTicketsLegalCatalog implements LegalCatalogProviderInterface
{
    /** First publication date of this project's legal documents. */
    private const string FIRST_REVISION = '2026-11-11';

    /** Directory of this project's legal deviation text files. */
    private const string TEXT_DIRECTORY = __DIR__ . '/Text';

    /**
     * Returns the published revisions on standard set version 1.
     *
     * @return array<string, list<LegalRevision>> Revisions per `LegalDocument` value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(
                    document: LegalDocument::TERMS,
                    id: self::FIRST_REVISION,
                    publishedOn: self::FIRST_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: self::FIRST_REVISION,
                    deviations: [],
                ),
            ],
            LegalDocument::PRIVACY->value => [
                new LegalRevision(
                    document: LegalDocument::PRIVACY,
                    id: self::FIRST_REVISION,
                    publishedOn: self::FIRST_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: self::FIRST_REVISION,
                    deviations: [
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_DELETION,
                            direction: DeviationDirection::STRICTER,
                            statement: 'Account deletion leaves numbered analytics events and network addresses',
                            textFile: self::TEXT_DIRECTORY . '/privacy/standard.deletion.2026-11-11.txt',
                        ),
                    ],
                ),
            ],
        ];
    }
}
