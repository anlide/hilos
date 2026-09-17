<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\Legal\LegalDocument;

/**
 * A revision was read that the installation does not declare (HIL-497).
 *
 * Raised for an unknown id, and for the latest revision of a document the installation declares
 * nothing for - a caller asks which documents exist before asking for their revisions.
 */
final class UnknownRevisionException extends LegalException
{
}
