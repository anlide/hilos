<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use ArrayAccess;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * Seeds the four doors of `ArrayAccess`, each with a contract of its own, so that a
 * report is only green when the brackets were resolved into the right one of them.
 *
 * The interface it implements is a built-in and stands outside the toy tree, which is
 * what makes these tags a local contract rather than a widening of an inherited one.
 *
 * @implements ArrayAccess<string, string>
 */
final class Indexed implements ArrayAccess
{
    /** @var array<string, string> Entries every door works on */
    private array $entries = [];

    /**
     * @param mixed $offset Key to look for
     * @return bool True when the catalog holds the key
     * @throws OtherException When the catalog cannot answer
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->entries[$offset]);
    }

    /**
     * @param mixed $offset Key to read
     * @return mixed Value behind the key
     * @throws NarrowException When the key is unknown
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->entries[$offset];
    }

    /**
     * @param mixed $offset Key to write
     * @param mixed $value Value to write behind it
     * @throws OtherException When the catalog refuses the key
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->entries[$offset] = $value;
    }

    /**
     * @param mixed $offset Key to drop
     * @throws NarrowException When the key may not be dropped
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->entries[$offset]);
    }
}
