<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/**
 * A machine-checkable code-style rule that reads one file at a time as PHP tokens.
 */
interface CodeStyleRule
{
    /**
     * @return string Stable rule id used in failure lines and baseline records
     */
    public function id(): string;

    /**
     * @return string Repository path of the document this rule enforces
     */
    public function doc(): string;

    /**
     * Reports every occurrence in the file, not every offending line.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> Hits found in this file
     */
    public function check(string $relativePath, array $tokens): iterable;
}
