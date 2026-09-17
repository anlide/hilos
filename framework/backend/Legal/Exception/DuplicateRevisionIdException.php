<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\Legal\LegalDocument;

/**
 * Two revisions of one document are declared under one id (HIL-497).
 *
 * A revision id lands in a URL and in the acceptance record, so it has to name exactly one text.
 */
final class DuplicateRevisionIdException extends LegalException
{
    /**
     * @param LegalDocument $document Document declaring the revisions
     * @param string $revisionId Id declared twice
     */
    public function __construct(LegalDocument $document, string $revisionId)
    {
        parent::__construct("Two revisions of {$document->value} are declared under id {$revisionId}");
    }
}
