<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Database\View\Item\LegalAcceptance;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\DTO\LegalAgreementsStateSignalData;
use Hilos\Legal\Exception\LegalException;
use Hilos\Utils\Helpers\TimeHelper;

/** Builds the acceptance, document text and revision-history wire sections (HIL-498). */
final class LegalAgreementsProjector
{
    public const string SECTION = 'legalAgreements';
    public const string TEXTS_SECTION = 'legalAgreementTexts';
    public const string REVISIONS_SECTION = 'legalRevisions';
    public const string TERMS_SECTION = 'legalTerms';

    /**
     * @param int $userId Person whose acceptance state to read
     * @param string $today Server calendar date, YYYY-MM-DD
     * @return LegalAgreementsStateSignalData Whole lightweight state
     * @throws HilosException When the catalog or acceptance lookup fails
     */
    public static function stateFor(int $userId, string $today): LegalAgreementsStateSignalData
    {
        $documents = LegalCatalogResolver::documents();
        $acceptances = $documents === [] ? [] : Hilos::$db->legalAcceptances->ofUser($userId);
        $state = [];
        foreach ($documents as $document) {
            $records = self::acceptedByRevision($document, $acceptances);
            $standing = LegalStandingResolver::standingOf($document, array_keys($records), $today);
            $accepted = [];
            foreach (LegalCatalogResolver::revisions($document) as $revision) {
                if (isset($records[$revision->id])) {
                    $accepted[] = ['revisionId' => $revision->id, 'acceptedAt' => TimeHelper::sqlToMs($records[$revision->id]->acceptedAt)];
                }
            }
            $state[] = [
                'document' => $document->value,
                'current' => LegalWire::revision(LegalCatalogResolver::latestRevision($document)),
                'held' => $standing->held === null ? null : LegalWire::revision($standing->held),
                'acceptedAt' => $standing->held === null ? null : TimeHelper::sqlToMs($records[$standing->held->id]->acceptedAt),
                'accepted' => $accepted,
                'standing' => $standing->standing->value,
                'deadline' => $standing->deadline,
            ];
        }

        return new LegalAgreementsStateSignalData($state);
    }

    /**
     * @param int $userId Person whose held text to include
     * @param string $today Server calendar date, YYYY-MM-DD
     * @return array<string, mixed> Current and held text, and their differences
     * @throws HilosException When a catalog, text or acceptance read fails
     */
    public static function textsFor(int $userId, string $today): array
    {
        $documents = LegalCatalogResolver::documents();
        $acceptances = $documents === [] ? [] : Hilos::$db->legalAcceptances->ofUser($userId);
        $texts = [];
        foreach ($documents as $document) {
            $current = LegalCatalogResolver::latestRevision($document);
            $held = LegalStandingResolver::standingOf($document, array_keys(self::acceptedByRevision($document, $acceptances)), $today)->held;
            $different = $held !== null && $held->id !== $current->id;
            $texts[] = [
                'document' => $document->value,
                'current' => LegalWire::clauses(LegalCatalogResolver::compose($document, $current->id)),
                'held' => $different ? LegalWire::clauses(LegalCatalogResolver::compose($document, $held->id)) : null,
                'changes' => $different ? LegalWire::changes(LegalRevisionComparison::between($document, $held->id, $current->id)) : [],
            ];
        }

        return [LegalAgreementsStateSignalData::documents => $texts];
    }

    /**
     * @return array<string, mixed> Every declared revision, in declaration order
     * @throws LegalException When the catalog is invalid
     */
    public static function revisions(): array
    {
        $documents = [];
        foreach (LegalCatalogResolver::documents() as $document) {
            $documents[] = ['document' => $document->value, 'revisions' => self::historyOf($document)];
        }

        return [LegalAgreementsStateSignalData::documents => $documents];
    }

    /**
     * The public Terms section (HIL-501): the revision in force with its text and the history to every
     * reader, and to a signed-in reader whose held revision is not the one in force, the comparison of
     * the two.
     *
     * @param ?int $userId Reader acting through the session, or null for a guest
     * @param string $today Server calendar date, YYYY-MM-DD
     * @return ?array<string, mixed> The section, or null when the project declares no Terms or its catalog is faulty
     * @throws HilosException When the acceptance lookup fails
     */
    public static function terms(?int $userId, string $today): ?array
    {
        $document = LegalDocument::TERMS;
        try {
            if (!in_array($document, LegalCatalogResolver::documents(), true)) {
                return null;
            }
            $current = LegalCatalogResolver::latestRevision($document);
            $held = $userId === null ? null : LegalStandingResolver::standingOf(
                $document, array_keys(self::acceptedByRevision($document, Hilos::$db->legalAcceptances->ofUser($userId))), $today,
            )->held;

            return [
                'current' => LegalWire::revision($current),
                'clauses' => LegalWire::clauses(LegalCatalogResolver::compose($document, $current->id)),
                'revisions' => self::historyOf($document),
                'changes' => $held === null || $held->id === $current->id ? null : [
                    'fromRevisionId' => $held->id,
                    'toRevisionId' => $current->id,
                    'changes' => LegalWire::changes(LegalRevisionComparison::between($document, $held->id, $current->id)),
                ],
            ];
        } catch (LegalException) {
            return null;
        }
    }

    /**
     * @param LegalDocument $document Document whose declarations to list
     * @return list<array<string, mixed>> Every declared revision with its origin, in declaration order
     * @throws LegalException When the catalog is invalid
     */
    private static function historyOf(LegalDocument $document): array
    {
        $revisions = [];
        $previous = null;
        foreach (LegalCatalogResolver::revisions($document) as $revision) {
            $revisions[] = [
                ...LegalWire::revision($revision),
                'origin' => LegalStandingResolver::origin($document, $revision->id)->value,
                'previousSetVersion' => $previous?->setVersion,
            ];
            $previous = $revision;
        }

        return $revisions;
    }

    /**
     * @param LegalDocument $document Document whose acceptance records to index
     * @param list<LegalAcceptance> $acceptances Persisted rows read for the wire boundary
     * @return array<string, LegalAcceptance> Records keyed by revision id
     */
    private static function acceptedByRevision(LegalDocument $document, array $acceptances): array
    {
        $records = [];
        foreach ($acceptances as $acceptance) {
            if ($acceptance->document === $document->value) {
                $records[$acceptance->revisionId] = $acceptance;
            }
        }

        return $records;
    }
}
