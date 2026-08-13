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
 * @extends \Hilos\Tests\CodeStyle\Baseline<\SplFileInfo>
 * @implements \Hilos\Tests\CodeStyle\CodeStyleRule
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

    /**
     * A compound type is one expression: a rule that cut the type at the first space
     * would read `array{0:` and never reach the second entry.
     *
     * @return array{0: \Socket, 1: \Socket} Socket pair
     */
    public function pair(): array
    {
        return [];
    }

    /**
     * The other two ways a cross-reference goes wrong once the backslash is gone:
     * {@see Rule\PhpDocFqnRule} reads against whatever namespace this file declares,
     * and {@see Baseline} points at nothing this file can reach.
     *
     * @return string Rule id the samples above belong to
     */
    public function owner(): string
    {
        return 'PHPDOC-FQN';
    }
}
