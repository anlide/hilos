<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\Legal\LegalDocument;

/**
 * A revision is declared under a document key other than its own document's (HIL-497).
 *
 * The same fault covers a key that names no document at all: no revision can belong to it.
 * Refused rather than regrouped, because a copied block with one field left unchanged is exactly
 * how this happens, and either reading of it may be the wrong one.
 */
final class MisplacedRevisionException extends LegalException
{
    /**
     * @param string $documentKey Document key the revision is declared under
     * @param LegalDocument $document Document the revision itself names
     * @param string $revisionId Misplaced revision
     */
    public function __construct(string $documentKey, LegalDocument $document, string $revisionId)
    {
        parent::__construct(
            "Revision {$revisionId} of {$document->value} is declared under legal document key {$documentKey}",
        );
    }
}
