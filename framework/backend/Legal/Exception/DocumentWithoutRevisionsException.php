<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

/**
 * A document key is declared with an empty list of revisions (HIL-497).
 *
 * A project that publishes nothing for a document omits its key; an empty list under the key
 * says the document exists while giving nobody a text to accept.
 */
final class DocumentWithoutRevisionsException extends LegalException
{
    /**
     * @param string $documentKey Document key declared empty, as the catalog wrote it
     */
    public function __construct(string $documentKey)
    {
        parent::__construct("Legal document {$documentKey} is declared with no revisions");
    }
}
