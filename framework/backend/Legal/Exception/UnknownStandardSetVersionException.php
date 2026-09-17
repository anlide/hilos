<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Hilos\Legal\LegalDocument;

/**
 * A standard set version was named that the framework does not carry (HIL-497).
 *
 * Raised when a project revision adopts a set version the framework never published, or when a
 * set is read by such a version directly.
 */
final class UnknownStandardSetVersionException extends LegalException
{
    /**
     * @param LegalDocument $document Document whose set was read
     * @param int $version Set version the framework does not carry
     */
    public function __construct(LegalDocument $document, int $version)
    {
        parent::__construct("The {$document->value} standard set has no version {$version}");
    }
}
