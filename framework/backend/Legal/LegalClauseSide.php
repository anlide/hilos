<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Legal\Exception\LegalTextFileMissingException;

/** The effective wording of one clause, independent of the standard it overrides. */
final readonly class LegalClauseSide
{
    /**
     * @param LegalClauseSource $source Origin of the effective wording
     * @param string $statement Effective statement
     * @param string $text Full effective text
     * @param ?DeviationDirection $direction Direction of a deviation, or null for the standard
     */
    public function __construct(
        public LegalClauseSource $source,
        public string $statement,
        public string $text,
        public ?DeviationDirection $direction,
    ) {
    }

    /**
     * @param ComposedClause $clause Clause with an optional project deviation
     * @return self Effective wording
     * @throws LegalTextFileMissingException When the wording file cannot be read
     */
    public static function of(ComposedClause $clause): self
    {
        return new self(
            $clause->deviation === null ? LegalClauseSource::STANDARD : LegalClauseSource::DEVIATION,
            $clause->deviation?->statement ?? $clause->standard->statement,
            LegalCatalogResolver::text($clause->deviation?->textFile ?? $clause->standard->textFile),
            $clause->deviation?->direction,
        );
    }
}
