<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use DateTimeImmutable;
use Hilos\Tests\CodeStyle\SourceScanner;
use Hilos\Tests\CodeStyle\Violation;
use OutOfBoundsException;
use SplFileInfo;

/**
 * Negative sample: the same references written the way phpdoc.md asks for, so a
 * rule that fires here is over-reporting.
 *
 * @method Violation build(SplFileInfo $file) Builds one violation
 */
final class PhpDocClean
{
    /**
     * Files collected so far, described by {@see SourceScanner::files}.
     *
     * @var SplFileInfo[] Scanned files
     */
    public array $files = [];

    /**
     * @param DateTimeImmutable $moment When the sample was produced
     * @return Violation Produced violation
     * @throws OutOfBoundsException When the sample index is unknown
     */
    public function produce(DateTimeImmutable $moment): mixed
    {
        return $moment;
    }
}
