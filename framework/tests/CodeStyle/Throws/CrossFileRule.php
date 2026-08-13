<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

use Hilos\Tests\CodeStyle\Violation;

/**
 * A machine-checkable rule that judges the source tree as a whole rather than one
 * file at a time — the third kind of check in this catalog, next to the single-file
 * `CodeStyleRule` and the markdown rules.
 *
 * It exists because the contract a call has to honour is written in the callee, and
 * the callee almost never sits in the file being read. `CodeStyleRule` cannot carry
 * that by construction: it is handed the tokens of one file, and widening its
 * signature would hand eight existing rules an index none of them reads.
 *
 * A hit is still a {@see Violation}, so the report line and the baseline record of a
 * cross-file rule read exactly like those of a single-file one.
 */
interface CrossFileRule
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
     * Reports every occurrence in the tree, not every offending line.
     *
     * @param SourceIndex $index Indexed source tree, paths addressed from the repository root
     * @return iterable<Violation> Hits found across the tree
     */
    public function check(SourceIndex $index): iterable;
}
