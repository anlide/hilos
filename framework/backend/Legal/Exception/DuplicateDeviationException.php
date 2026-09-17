<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\Legal\LegalDocument;

/**
 * A revision declares two deviations over one standard clause (HIL-497).
 *
 * A deviation replaces its clause's text in place, so a second one over the same clause leaves
 * the composed document with no single answer to what that clause says.
 */
final class DuplicateDeviationException extends LegalException
{
    /**
     * @param LegalDocument $document Document of the revision
     * @param string $revisionId Revision declaring the deviations
     * @param string $clauseKey Clause key named twice
     */
    public function __construct(LegalDocument $document, string $revisionId, string $clauseKey)
    {
        parent::__construct("Revision {$revisionId} of {$document->value} deviates from clause {$clauseKey} twice");
    }
}
