<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * LegalCatalogStub - Stub-example of a project legal catalog.
 *
 * Project copies this to declare its own documents. One document, one revision and one
 * deviation, so that every field is filled in at least once; a real project declares both
 * documents, and a project that departs from nothing declares its revisions with an empty
 * deviation list. The facade leaves this unbound: an installation that points nothing at a
 * catalog publishes no legal documents at all.
 *
 * @see LegalCatalogProviderInterface
 */
final class LegalCatalogStub implements LegalCatalogProviderInterface
{
    /** @var string Directory of this catalog's deviation text files, one subdirectory per document */
    private const string TEXT_DIRECTORY = __DIR__ . '/Stub';

    /** @var string Publication date of the stub revision, which is also its id */
    private const string REVISION_PUBLISHED_ON = '2026-09-17';

    /**
     * Returns one terms revision on standard set version 1 with one looser deviation.
     *
     * @return array<string, list<LegalRevision>> Revisions per `LegalDocument` value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(
                    document: LegalDocument::TERMS,
                    id: self::REVISION_PUBLISHED_ON,
                    publishedOn: self::REVISION_PUBLISHED_ON,
                    setVersion: 1,
                    significance: LegalSignificance::SUBSTANTIAL,
                    effectiveOn: '2026-09-17',
                    deviations: [
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_RETENTION,
                            direction: DeviationDirection::LOOSER,
                            statement: 'Content is kept for 30 days',
                            textFile: self::TEXT_DIRECTORY . '/terms/standard.retention.2026-09-17.txt',
                        ),
                    ],
                ),
            ],
        ];
    }
}
