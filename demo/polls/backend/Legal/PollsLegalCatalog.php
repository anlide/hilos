<?php

declare(strict_types=1);

namespace Demo\Polls\Legal;

use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;

/**
 * PollsLegalCatalog - The polls demo's legal documents: a project that departs from nothing.
 *
 * Both documents stand on the framework standard as it is. That is a declared state, not a
 * missing one: the consent screen says there are no deviations, and says so honestly. A revision
 * once published stays here for good: a person may hold it.
 */
final class PollsLegalCatalog implements LegalCatalogProviderInterface
{
    /** @var string First terms revision, named by its publication date */
    private const string TERMS_FIRST_REVISION = '2026-09-17';

    /** @var string First privacy revision, named by its publication date */
    private const string PRIVACY_FIRST_REVISION = '2026-09-17';

    /**
     * Returns one revision per document on standard set version 1, without deviations.
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
            ],
        ];
    }
}
