<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * StandardSet - one published version of a document's framework standard set.
 *
 * The version line is the document's own and separate from the Hilos release number: a release
 * may leave the legal part alone, and a change to the standard may ship without a release.
 */
final readonly class StandardSet
{
    /**
     * @param LegalDocument $document Document this set belongs to
     * @param int $version Set version, ascending from 1 without gaps
     * @param string $publishedOn Publication date, `YYYY-MM-DD`
     * @param LegalSignificance $significance How much this version changed against the previous one
     * @param list<StandardClause> $clauses Clauses in document order
     */
    public function __construct(
        public LegalDocument $document,
        public int $version,
        public string $publishedOn,
        public LegalSignificance $significance,
        public array $clauses,
    ) {
    }
}
