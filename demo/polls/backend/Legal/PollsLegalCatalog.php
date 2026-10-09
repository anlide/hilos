<?php

declare(strict_types=1);

namespace Demo\Polls\Legal;

use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSetCatalog;

/**
 * PollsLegalCatalog - The polls demo's legal documents.
 *
 * Current Terms declare the demo-reset exception to availability. Current Privacy declares the analytics
 * exception to account erasure. A revision once published stays here for good; earlier revisions remain
 * available to people who accepted them.
 */
final class PollsLegalCatalog implements LegalCatalogProviderInterface
{
    /** @var string First terms revision, named by its publication date */
    private const string TERMS_FIRST_REVISION = '2026-09-17';

    /** Substantial revision stating that the demo's data may be wiped at any time. */
    private const string TERMS_DEMO_RESET_REVISION = '2026-10-01';

    /** Editorial wording revision of the demo-reset availability clause. */
    private const string TERMS_WORDING_REVISION = '2026-10-07';

    /** @var string First privacy revision, named by its publication date */
    private const string PRIVACY_FIRST_REVISION = '2026-09-17';

    /** Current Privacy revision declaring the analytics exception. */
    private const string PRIVACY_ANALYTICS_REVISION = '2026-10-09';

    /** Directory of this project's legal deviation text files. */
    private const string TEXT_DIRECTORY = __DIR__ . '/Text';

    /**
     * Returns the published revisions on standard set version 1, with the polls demo's deviations.
     *
     * @return array<string, list<LegalRevision>> Revisions per `LegalDocument` value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(
                    document: LegalDocument::TERMS,
                    id: self::TERMS_FIRST_REVISION,
                    publishedOn: self::TERMS_FIRST_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: '2026-09-17',
                    deviations: [],
                ),
                new LegalRevision(
                    document: LegalDocument::TERMS,
                    id: self::TERMS_DEMO_RESET_REVISION,
                    publishedOn: self::TERMS_DEMO_RESET_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: self::TERMS_DEMO_RESET_REVISION,
                    deviations: [
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_AVAILABILITY,
                            direction: DeviationDirection::STRICTER,
                            statement: 'This is a demo: its data may be wiped at any time',
                            textFile: self::TEXT_DIRECTORY . '/terms/standard.availability.2026-10-01.txt',
                        ),
                    ],
                ),
                new LegalRevision(
                    document: LegalDocument::TERMS,
                    id: self::TERMS_WORDING_REVISION,
                    publishedOn: self::TERMS_WORDING_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::EDITORIAL,
                    effectiveOn: self::TERMS_WORDING_REVISION,
                    deviations: [
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_AVAILABILITY,
                            direction: DeviationDirection::STRICTER,
                            statement: 'This is a demo: its data may be wiped at any time',
                            textFile: self::TEXT_DIRECTORY . '/terms/standard.availability.2026-10-07.txt',
                        ),
                    ],
                ),
            ],
            LegalDocument::PRIVACY->value => [
                new LegalRevision(
                    document: LegalDocument::PRIVACY,
                    id: self::PRIVACY_FIRST_REVISION,
                    publishedOn: self::PRIVACY_FIRST_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: '2026-09-17',
                    deviations: [],
                ),
                new LegalRevision(
                    document: LegalDocument::PRIVACY,
                    id: self::PRIVACY_ANALYTICS_REVISION,
                    publishedOn: self::PRIVACY_ANALYTICS_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: self::PRIVACY_ANALYTICS_REVISION,
                    deviations: [
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_DELETION,
                            direction: DeviationDirection::STRICTER,
                            statement: 'Account deletion leaves numbered analytics events and network addresses',
                            textFile: self::TEXT_DIRECTORY . '/privacy/standard.deletion.2026-10-09.txt',
                        ),
                    ],
                ),
            ],
        ];
    }
}
