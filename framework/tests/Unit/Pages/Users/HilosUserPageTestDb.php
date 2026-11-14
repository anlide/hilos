<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Users;

use ArrayAccess;
use Hilos\Database\Context\HilosDbContext;

/**
 * People for the person card, with no database behind them (HIL-1957).
 *
 * The card names the person's row before its first frame, then reads their standing and begun
 * erasure. Here the named people are present, nobody is folded, nothing is scheduled and no
 * erasure has begun, so the card reads a plain standing.
 */
final class HilosUserPageTestDb extends HilosDbContext
{
    /** @var array<string, HilosUserPageTestRows> Rows by collection name, kept so a case reads what was asked */
    private array $rows = [];

    /**
     * @param list<int> $userIds People present in the person table
     */
    public function __construct(private readonly array $userIds)
    {
    }

    /**
     * @param string $name Collection name
     * @return HilosUserPageTestRows Rows of that collection
     */
    public function __get(string $name)
    {
        return $this->rows[$name] ??= new HilosUserPageTestRows($name === self::users ? $this->userIds : []);
    }
}

/** Rows keyed by primary id; every other read the standing makes finds nothing. */
final class HilosUserPageTestRows implements ArrayAccess
{
    /** @var list<mixed> Offsets read, in order */
    public array $lookups = [];

    /**
     * @param list<int> $ids Primary ids present
     */
    public function __construct(private readonly array $ids)
    {
    }

    /**
     * @param mixed $offset Primary id asked for
     * @return bool Whether that id is present
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * @param mixed $offset Primary id asked for
     * @return ?HilosUserPageTestUser Row, or null when the id is absent
     */
    public function offsetGet(mixed $offset): mixed
    {
        $this->lookups[] = $offset;

        return in_array($offset, $this->ids, true) ? new HilosUserPageTestUser() : null;
    }

    /**
     * @param int $userId Person whose scheduled deletion is asked for
     * @return null Nothing is scheduled
     */
    public function liveOf(int $userId): mixed
    {
        return null;
    }

    /**
     * @param int $userId Person whose begun erasure is asked for
     * @return null No erasure has begun
     */
    public function openErasureCovering(int $userId): mixed
    {
        return null;
    }

    /**
     * @param mixed $offset Unused
     * @param mixed $value Unused
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    /**
     * @param mixed $offset Unused
     */
    public function offsetUnset(mixed $offset): void
    {
    }
}

/** A person row that is not blocked. */
final class HilosUserPageTestUser
{
    public bool $block = false;
}
