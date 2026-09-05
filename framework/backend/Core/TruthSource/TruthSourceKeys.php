<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

/**
 * TruthSourceKeys - the width of one truth-source claim: every row of a collection, or the rows
 * named one by one.
 *
 * Two named states and no third. What it replaces was `list<string>|true`, where the absence of a
 * list said both "the whole collection" and "the list was never collected", and a reader had no
 * way to tell the two apart. Every value here comes from a factory that says which state it is,
 * so a claim cannot be written without naming its width out loud - which is why the constructor
 * is private and why the parameter it fills has no default anywhere.
 *
 * A width of no rows at all is not the absence of a claim: it is what the right to create is
 * ({@see TruthSourceRegistry::registerCreate()}), because minting a record is not owning one.
 */
final readonly class TruthSourceKeys
{
    /**
     * @param bool $everyKey Whether the claim runs over the whole collection
     * @param list<string> $keys Rows the claim names, empty when it runs over the whole collection
     */
    private function __construct(
        private bool $everyKey,
        private array $keys,
    ) {
    }

    /**
     * @return self A claim over every row of the collection
     */
    public static function all(): self
    {
        return new self(true, []);
    }

    /**
     * @param string ...$keys Rows the claim covers; name none of them for a claim that owns no row
     * @return self A claim over the named rows alone
     */
    public static function listed(string ...$keys): self
    {
        return new self(false, $keys);
    }

    /**
     * @return bool True when the claim runs over the whole collection
     */
    public function coversEveryKey(): bool
    {
        return $this->everyKey;
    }

    /**
     * @return bool True when the claim owns no row at all - the width the right to create has
     */
    public function coversNoKey(): bool
    {
        return !$this->everyKey && $this->keys === [];
    }

    /**
     * @param string $key Row key to ask about
     * @return bool True when this claim covers that row
     */
    public function covers(string $key): bool
    {
        return $this->everyKey || in_array($key, $this->keys, true);
    }

    /**
     * @return list<string> Rows named one by one, empty when the claim runs over the whole collection
     */
    public function listedKeys(): array
    {
        return $this->keys;
    }
}
