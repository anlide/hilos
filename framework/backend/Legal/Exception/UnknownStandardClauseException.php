<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\Legal\LegalDocument;

/**
 * A deviation names a clause absent from the set version its revision adopts (HIL-497).
 *
 * The clause is judged against THAT set version, not the latest one: a clause dropped by a later
 * set is still a valid target for a revision that stays on the earlier one.
 */
final class UnknownStandardClauseException extends LegalException
{
    /**
     * @param LegalDocument $document Document of the revision
     * @param string $revisionId Revision declaring the deviation
     * @param string $clauseKey Clause key the deviation names
     * @param int $setVersion Set version the revision adopts
     */
    public function __construct(LegalDocument $document, string $revisionId, string $clauseKey, int $setVersion)
    {
        parent::__construct(
            "Revision {$revisionId} of {$document->value} deviates from clause {$clauseKey}, "
            . "which standard set version {$setVersion} does not carry",
        );
    }
}
