<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * Seeds the two receiver forms that need no variable at all: a call by class name
 * and a call through a static property with a declared type.
 */
final class Registry
{
    /** @var array<int, string> Entries handed out by reference */
    private array $store = [];

    /**
     * @param string $key Key to look up
     * @return string Value behind the key
     * @throws NarrowException When the key is unknown
     */
    public static function lookup(string $key): string
    {
        return $key;
    }

    /**
     * @return string Name of the entry this instance stands for
     * @throws OtherException When the entry has gone
     */
    public function name(): string
    {
        return 'entry';
    }

    /**
     * Shares a name with {@see SourceInterface::read()} and throws something else, so
     * a receiver resolved from the wrong declaration is visible in the report.
     *
     * @return string Text of the entry
     * @throws OtherException When the entry has gone
     */
    public function read(): string
    {
        return 'text';
    }

    /**
     * A method named with a reserved word, which PHP hands back under its own keyword
     * token rather than as an ordinary name.
     *
     * @param string $path Path to match
     * @return bool True when the path matches
     * @throws NarrowException When the pattern is malformed
     */
    public function match(string $path): bool
    {
        return $path !== '';
    }

    /**
     * A by-reference method, whose marker is a token type of its own since PHP 8.1.
     *
     * @return array<int, string> Entries, by reference
     * @throws OtherException When the store is not open
     */
    public function &entries(): array
    {
        return $this->store;
    }
}
