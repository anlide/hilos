<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/** The records one guard piece owns, independently of what its current scan finds. */
final class BaselineScope
{
    /**
     * @param list<string> $ruleIds Rules whose records this piece owns
     * @param string|null $root Exact source root, or null for every path of these rules
     */
    private function __construct(private readonly array $ruleIds, private readonly ?string $root)
    {
    }

    /**
     * @param string $root Source root relative to the repository
     * @param list<string> $ruleIds Scanning rules, including those with no current findings
     * @return self Records of these scanning rules below this exact root
     */
    public static function forRoot(string $root, array $ruleIds): self
    {
        return new self($ruleIds, $root);
    }

    /**
     * @param list<string> $ruleIds Index rules whose records this piece owns at every path
     * @return self Records of these rules across the repository
     */
    public static function forRules(array $ruleIds): self
    {
        return new self($ruleIds, null);
    }

    /**
     * @param string $key Baseline key, "<rule id> <path from repository root>"
     * @return bool Whether this piece owns the rule and its path
     */
    public function contains(string $key): bool
    {
        $parts = explode(' ', $key, 2);

        return count($parts) === 2 && in_array($parts[0], $this->ruleIds, true)
            && ($this->root === null || str_starts_with($parts[1], $this->root . '/'));
    }
}
