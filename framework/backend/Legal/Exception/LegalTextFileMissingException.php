<?php

declare(strict_types=1);

namespace Hilos\Legal\Exception;

use Throwable;

/**
 * A declared clause or deviation text file is absent, unreadable or empty (HIL-497).
 */
final class LegalTextFileMissingException extends LegalException
{
    /**
     * @param string $textFile Absolute path of the declared text file
     * @param ?Throwable $previous File-system failure behind it, when there was one
     */
    public function __construct(string $textFile, ?Throwable $previous = null)
    {
        parent::__construct("Legal text file is missing or empty: {$textFile}", 0, $previous);
    }
}
