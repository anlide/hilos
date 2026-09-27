<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\Exception\LegalTextFileMissingException;
use Hilos\Legal\Exception\ReversedComparisonException;
use Hilos\Legal\Exception\UnknownStandardSetVersionException;

/** Compares effective clauses, keeping the newer set's order and appending removed clauses. */
final class LegalRevisionComparison
{
    /**
     * @param LegalDocument $document Document to compare
     * @param string $fromId Earlier revision id
     * @param string $toId Later revision id
     * @return list<LegalClauseChange> Changed clauses for the wire projection
     * @throws LegalException When a revision, catalog or text file is invalid
     * @throws ReversedComparisonException When the revisions are equal or reversed
     */
    public static function between(LegalDocument $document, string $fromId, string $toId): array
    {
        $from = LegalCatalogResolver::compose($document, $fromId);
        $to = LegalCatalogResolver::compose($document, $toId);
        $ids = array_map(static fn (LegalRevision $revision): string => $revision->id, LegalCatalogResolver::revisions($document));
        if (array_search($fromId, $ids, true) >= array_search($toId, $ids, true)) {
            throw new ReversedComparisonException("Revision {$fromId} must precede {$toId} of {$document->value}");
        }

        return self::diff($from->clauses, $to->clauses);
    }

    /**
     * @param LegalDocument $document Document whose standard sets to compare
     * @param int $fromVersion Adopted set version
     * @param int $toVersion Available set version
     * @return list<LegalClauseChange> Standard changes for the wire projection, without project deviations
     * @throws UnknownStandardSetVersionException When either set version does not exist
     * @throws LegalTextFileMissingException When a standard clause's text cannot be read
     */
    public static function standardSets(LegalDocument $document, int $fromVersion, int $toVersion): array
    {
        return self::diff(
            array_map(static fn (StandardClause $clause): ComposedClause => new ComposedClause($clause, null),
                StandardSetCatalog::set($document, $fromVersion)->clauses),
            array_map(static fn (StandardClause $clause): ComposedClause => new ComposedClause($clause, null),
                StandardSetCatalog::set($document, $toVersion)->clauses),
        );
    }

    /**
     * @param list<ComposedClause> $from Earlier composed clauses at the comparison boundary
     * @param list<ComposedClause> $to Later composed clauses at the comparison boundary
     * @return list<LegalClauseChange> Effective differences in later-clause order, then removals
     * @throws LegalTextFileMissingException When a clause's text cannot be read
     */
    private static function diff(array $from, array $to): array
    {
        $before = [];
        foreach ($from as $clause) {
            $before[$clause->standard->key] = $clause;
        }
        $changes = [];
        foreach ($to as $clause) {
            $earlier = isset($before[$clause->standard->key]) ? LegalClauseSide::of($before[$clause->standard->key]) : null;
            $later = LegalClauseSide::of($clause);
            if (
                $earlier === null || $earlier->text !== $later->text || $earlier->statement !== $later->statement
                || $earlier->source !== $later->source || $earlier->direction !== $later->direction
            ) {
                $changes[] = new LegalClauseChange(
                    $clause->standard->key,
                    $clause->standard->statement,
                    $earlier === null ? LegalChangeKind::ADDED : LegalChangeKind::CHANGED,
                    $earlier,
                    $later,
                );
            }
            unset($before[$clause->standard->key]);
        }
        foreach ($before as $clause) {
            $changes[] = new LegalClauseChange(
                $clause->standard->key,
                $clause->standard->statement,
                LegalChangeKind::REMOVED,
                LegalClauseSide::of($clause),
                null,
            );
        }

        return $changes;
    }
}
