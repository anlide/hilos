<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * ComposedDocument - a revision composed over the standard set it adopts.
 *
 * The clauses follow the set's declaration order: a deviation replaces its clause in place and
 * never reorders or appends. A revision without deviations composes to the standard set as is.
 */
final readonly class ComposedDocument
{
    /**
     * @param LegalDocument $document Document composed
     * @param LegalRevision $revision Revision composed
     * @param StandardSet $set Standard set version the revision adopts
     * @param list<ComposedClause> $clauses Composed clauses in the set's order
     */
    public function __construct(
        public LegalDocument $document,
        public LegalRevision $revision,
        public StandardSet $set,
        public array $clauses,
    ) {
    }
}
