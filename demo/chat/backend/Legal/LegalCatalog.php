<?php

declare(strict_types=1);

namespace Demo\Chat\Legal;

use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSetCatalog;

/**
 * LegalCatalog - The chat's legal documents: the project that departs from the standard.
 *
 * Chat keeps more and shows more than the standard promises - people moderate, messages and
 * files stay, files open by link - and keeps less in one place: it records no access logs. Each
 * of those is a deviation of the revision that declares it, never an edit of the framework text.
 * A revision once published stays here for good: a person may hold it.
 */
final class LegalCatalog implements LegalCatalogProviderInterface
{
    /** @var string First terms revision, named by its publication date */
    private const string TERMS_FIRST_REVISION = '2026-09-17';

    /** @var string First privacy revision, named by its publication date */
    private const string PRIVACY_FIRST_REVISION = '2026-09-17';

    /** @var string Directory of the chat's deviation text files, one subdirectory per document */
    private const string TEXT_DIRECTORY = __DIR__ . '/Text';

    /**
     * Returns one revision per document on standard set version 1, with the chat's deviations.
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
                    deviations: [
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_MODERATION,
                            direction: DeviationDirection::STRICTER,
                            statement: 'Conversations are visible to moderators',
                            textFile: self::TEXT_DIRECTORY . '/terms/standard.moderation.2026-09-17.txt',
                        ),
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_RETENTION,
                            direction: DeviationDirection::STRICTER,
                            statement: 'Messages are kept indefinitely',
                            textFile: self::TEXT_DIRECTORY . '/terms/standard.retention.2026-09-17.txt',
                        ),
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_FILE_ACCESS,
                            direction: DeviationDirection::STRICTER,
                            statement: 'Files are reachable by direct link',
                            textFile: self::TEXT_DIRECTORY . '/terms/standard.file_access.2026-09-17.txt',
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
                    deviations: [
                        new Deviation(
                            clauseKey: StandardSetCatalog::CLAUSE_ACCESS_LOG,
                            direction: DeviationDirection::LOOSER,
                            statement: 'No access logs are kept at all',
                            textFile: self::TEXT_DIRECTORY . '/privacy/standard.access_log.2026-09-17.txt',
                        ),
                    ],
                ),
            ],
        ];
    }
}
