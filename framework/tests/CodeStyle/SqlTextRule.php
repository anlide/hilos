<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/** A code-style rule that reads migration SQL as source text. */
interface SqlTextRule
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
     * @param string $relativePath File path relative to the scanned root
     * @param string $text Contents of the SQL file
     * @return iterable<Violation> Hits found in this file
     */
    public function checkSql(string $relativePath, string $text): iterable;
}
