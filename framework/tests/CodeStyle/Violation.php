<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/**
 * One code-style rule hit: which rule fired, in which file, on which line.
 */
final readonly class Violation
{
    /**
     * @param string $ruleId Rule that produced the hit
     * @param string $relativePath File path relative to the scanned root
     * @param int $line Line the hit sits on
     * @param string $message Short human-readable essence of the hit
     */
    public function __construct(
        public string $ruleId,
        public string $relativePath,
        public int $line,
        public string $message,
    ) {
    }

    /**
     * Rules see a path relative to the root they scan; a report that spans several
     * roots puts the root back in front.
     *
     * @param string $prefix Path of the scanned root, relative to the repository
     * @return self Same hit, addressed from the repository root
     */
    public function withPathPrefix(string $prefix): self
    {
        return new self($this->ruleId, $prefix . '/' . $this->relativePath, $this->line, $this->message);
    }

    /**
     * Failure line shown by the guard tests; the doc link comes from the owning rule.
     *
     * @param string $doc Repository path of the document that owns the rule
     * @return string Single reportable line
     */
    public function describe(string $doc): string
    {
        return sprintf('%s %s:%d — %s (see %s)', $this->ruleId, $this->relativePath, $this->line, $this->message, $doc);
    }
}
