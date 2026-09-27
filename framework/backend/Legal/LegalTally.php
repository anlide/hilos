<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Legal\Exception\LegalException;

/** Counts acceptance records and distinct holders against the current declarations. */
final readonly class LegalTally
{
    /**
     * @param string $document Stored document key
     * @param bool $declared Whether the catalog declares this document
     * @param int $covered People covered by their latest declared acceptance
     * @param int $window People before their nearest outstanding deadline
     * @param int $lapsed People at or past their nearest outstanding deadline
     * @param array<string, int> $heldByRevision Latest declared acceptance per person, keyed by revision
     * @param array<string, int> $acceptedByRevision Acceptance records per revision
     * @param array<string, int> $undeclared People with an acceptance of each undeclared revision
     */
    public function __construct(
        public string $document,
        public bool $declared,
        public int $covered,
        public int $window,
        public int $lapsed,
        public array $heldByRevision,
        public array $acceptedByRevision,
        public array $undeclared,
    ) {
    }

    /**
     * People without a declared acceptance do not enter the three coverage counts.
     * The two input histograms are SQL aggregate projections, not acceptance rows.
     *
     * @param string $document Stored document key, including keys no longer declared
     * @param array<string, int> $heldByRevision People by their latest accepted declared revision
     * @param array<string, int> $acceptedByRevision Records by revision, including undeclared ones
     * @param string $today Server calendar date, YYYY-MM-DD
     * @return self Counts for this document on that date
     * @throws LegalException When the catalog declaration is faulty
     */
    public static function of(string $document, array $heldByRevision, array $acceptedByRevision, string $today): self
    {
        $kind = LegalDocument::tryFrom($document);
        $declared = $kind !== null && in_array($kind, LegalCatalogResolver::documents(), true);
        $revisionIds = $declared
            ? array_map(static fn (LegalRevision $revision): string => $revision->id, LegalCatalogResolver::revisions($kind))
            : [];
        $covered = $window = $lapsed = 0;
        if ($declared) {
            foreach ($heldByRevision as $id => $people) {
                match (LegalStandingResolver::standingOf($kind, [(string) $id], $today)->standing) {
                    LegalStanding::COVERED => $covered += $people,
                    LegalStanding::WINDOW => $window += $people,
                    LegalStanding::LAPSED => $lapsed += $people,
                    LegalStanding::NONE => null,
                };
            }
        }

        return new self(
            $document,
            $declared,
            $covered,
            $window,
            $lapsed,
            $heldByRevision,
            $acceptedByRevision,
            array_diff_key($acceptedByRevision, array_flip($revisionIds)),
        );
    }

    /**
     * @param string $today Server calendar date, YYYY-MM-DD
     * @return array<string, self> Admin projection map, declared documents first and recorded-only documents after
     * @throws LegalException When the catalog declaration is faulty
     * @throws DatabaseException When acceptance records cannot be read
     */
    public static function all(string $today): array
    {
        $documents = array_map(static fn (LegalDocument $document): string => $document->value, LegalCatalogResolver::documents());
        $tallies = [];
        foreach (array_unique([...$documents, ...Hilos::$db->legalAcceptances->documentsOnRecord()]) as $document) {
            $declaredIds = in_array($document, $documents, true)
                ? array_map(
                    static fn (LegalRevision $revision): string => $revision->id,
                    LegalCatalogResolver::revisions(LegalDocument::from($document)),
                )
                : [];
            $tallies[$document] = self::of(
                $document,
                $declaredIds === [] ? [] : Hilos::$db->legalAcceptances->heldCounts($document, $declaredIds),
                Hilos::$db->legalAcceptances->acceptedCounts($document),
                $today,
            );
        }

        return $tallies;
    }
}
