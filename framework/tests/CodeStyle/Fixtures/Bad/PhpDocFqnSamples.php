<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use DateTimeImmutable;

/**
 * Deliberately broken sample: every leading-backslash reference below must be
 * reported by PHPDOC-FQN. Never autoloaded — this file exists to be read as text.
 *
 * @property-read \Hilos\Core\Hilos $facade Facade handle
 * @method \Hilos\Tests\CodeStyle\Violation build(\SplFileInfo $file) Builds one violation
 */
final class PhpDocFqnSamples
{
    /**
     * Files collected so far, described by {@see \Hilos\Tests\CodeStyle\SourceScanner::files}.
     *
     * @var \SplFileInfo[] Scanned files
     */
    public array $files = [];

    /**
     * @param \DateTimeImmutable $moment When the sample was produced
     * @return \Hilos\Tests\CodeStyle\Violation Produced violation
     * @throws \OutOfBoundsException When the sample index is unknown
     */
    public function produce(DateTimeImmutable $moment): mixed
    {
        return $moment;
    }
}
