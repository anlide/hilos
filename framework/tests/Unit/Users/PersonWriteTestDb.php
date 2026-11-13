<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use ArrayAccess;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Users\AddressablePerson;

/**
 * People and merges for a write of one person's agent, with no database behind them (HIL-1703).
 *
 * The agent checks the person at the entry of every frame ({@see AddressablePerson::require()})
 * before the write a case replaces. Here every person is present, nobody is folded and no erasure
 * has begun (HIL-1939), so the check passes and the case reads the write and what follows it.
 */
final class PersonWriteTestDb extends HilosDbContext
{
    /**
     * @param string $name Collection name
     * @return PersonWriteTestRows Rows of that collection
     */
    public function __get(string $name)
    {
        return new PersonWriteTestRows($name);
    }
}

/** The three reads the address check makes: a person at every key, a merge at none, no begun erasure. */
final class PersonWriteTestRows implements ArrayAccess
{
    /**
     * @param string $name Collection the rows stand for
     */
    public function __construct(private string $name)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->name === HilosDbContext::users;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->name === HilosDbContext::users ? new PersonWriteTestUser() : null;
    }

    /**
     * @param int $userId Person whose begun erasure is asked for
     * @return null No erasure has begun
     */
    public function startedOf(int $userId): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

/** A person row that is there, and carries nothing a replaced write would read. */
final class PersonWriteTestUser
{
}
