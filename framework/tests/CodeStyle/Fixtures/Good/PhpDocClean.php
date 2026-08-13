<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use DateTimeImmutable;
use Hilos\Tests\CodeStyle\SourceScanner;
use Hilos\Tests\CodeStyle\Violation;
use OutOfBoundsException;
use Socket;
use SplFileInfo;

/**
 * Negative sample: the same references written the way phpdoc.md asks for, so a
 * rule that fires here is over-reporting.
 *
 * @method Violation build(SplFileInfo $file) Builds one violation
 */
final class PhpDocClean
{
    /** Id the samples of this file belong to, pointed at by {@see OWNING_RULE} below. */
    public const string OWNING_RULE = 'PHPDOC-FQN';

    /**
     * Files collected so far, described by {@see SourceScanner::files}. A neighbour of
     * this namespace, {@see PhpDocCleanKind}, needs no import for the same reason.
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

    /**
     * The legal counterpart of the compound type: read whole, it holds two short
     * names and both of them are imported.
     *
     * @return array{0: Socket, 1: Socket} Socket pair
     */
    public function pair(): array
    {
        return [];
    }
}
