<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Legal;

use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSetCatalog;

/**
 * EcommerceShopLegalCatalog - The ecommerce-shop demo's legal documents.
 *
 * The Terms document follows the framework standard. Current Privacy declares the analytics exception to
 * account erasure. Earlier revisions remain available to people who accepted them.
 */
final class EcommerceShopLegalCatalog implements LegalCatalogProviderInterface
{
    /** @var string First terms revision, named by its publication date */
    private const string TERMS_FIRST_REVISION = '2026-09-30';

    /** @var string First privacy revision, named by its publication date */
    private const string PRIVACY_FIRST_REVISION = '2026-09-30';

    /** Current Privacy revision declaring the analytics exception. */
    private const string PRIVACY_ANALYTICS_REVISION = '2026-10-09';

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
                    id: self::TERMS_FIRST_REVISION,
                    publishedOn: self::TERMS_FIRST_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: '2026-09-30',
                    deviations: [],
                ),
            ],
            LegalDocument::PRIVACY->value => [
                new LegalRevision(
                    document: LegalDocument::PRIVACY,
                    id: self::PRIVACY_FIRST_REVISION,
                    publishedOn: self::PRIVACY_FIRST_REVISION,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: '2026-09-30',
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
