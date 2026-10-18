<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Runtime\State\Collection;

use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\AuthAttempt;
use Hilos\Runtime\State\Item\RtState;
use OutOfBoundsException;

/**
 * Deliberately broken sample: a state collection that narrows its readers in tags alone. A tag
 * cannot make offsetGet() throw on a missing row, so neither tag counts and both readers are
 * reported on the line of the class keyword.
 *
 * @extends RtStates<AuthAttempt>
 * @method AuthAttempt|null get(?string $id)
 * @method AuthAttempt offsetGet(mixed $offset)
 */
class CollectionItemNarrowingSamples extends RtStates
{
    public const string STATE_CLASS = AuthAttempt::class;
}

/**
 * Deliberately broken sample: offsetGet() is narrowed for real and get() is not declared at all.
 *
 * @extends RtStates<AuthAttempt>
 */
class MissingGet extends RtStates
{
    public const string STATE_CLASS = AuthAttempt::class;

    /**
     * @param mixed $offset State key
     * @return AuthAttempt Required row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): AuthAttempt
    {
        return parent::offsetGet($offset) ?? throw new OutOfBoundsException('Attempt counter not found');
    }
}

/**
 * Deliberately broken sample: both readers are real methods, and both answer the base RtState
 * the collection inherits rather than the state its constant names.
 *
 * @extends RtStates<AuthAttempt>
 */
class BaseStateReaders extends RtStates
{
    public const string STATE_CLASS = AuthAttempt::class;

    /**
     * @param ?string $id State key, or null for a missing optional key
     * @return ?RtState The base row, not narrowed
     */
    public function get(?string $id): ?RtState
    {
        return parent::get($id);
    }

    /**
     * @param mixed $offset State key
     * @return RtState The base row, not narrowed
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): RtState
    {
        return parent::offsetGet($offset) ?? throw new OutOfBoundsException('Base row not found');
    }
}
