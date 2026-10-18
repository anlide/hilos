<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Runtime\State\Collection;

use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\AuthAttempt;
use Hilos\Runtime\State\Item\HilosConnection;
use OutOfBoundsException;

/**
 * Negative sample of what the rule is obliged to let through: both readers are real methods
 * answering the state the constant names, the way a concrete state collection is written. A
 * single reported line in this file means the rule has grown wider than the document it enforces.
 *
 * @extends RtStates<AuthAttempt>
 */
class CollectionItemNarrowingLookAlikes extends RtStates
{
    public const string STATE_CLASS = AuthAttempt::class;

    /**
     * @param ?string $key Throttle key, or null for a missing optional key
     * @return ?AuthAttempt Attempt counter, or null when missing
     */
    public function get(?string $key): ?AuthAttempt
    {
        /** @var ?AuthAttempt $state */
        $state = parent::get($key);

        return $state;
    }

    /**
     * Array access is for required counters; use `get()` when absence is valid.
     *
     * @param mixed $offset Throttle key
     * @return AuthAttempt Attempt counter
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): AuthAttempt
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Attempt counter not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Attempt counter not found: {$offset}");
    }
}

/**
 * Negative sample: an inheritable base that leaves the state to its subclasses names none, so
 * it is not judged.
 *
 * @template T of HilosConnection
 * @extends RtStates<T>
 */
abstract class GenericStateBase extends RtStates
{
}

/** The base of the whole layer: its empty constant names no state. */
abstract class EmptyStateBase extends RtStates
{
    public const string STATE_CLASS = '';
}

/** A subclass inheriting the constant is outside the lexical read. */
final class InheritsTheState extends CollectionItemNarrowingLookAlikes
{
}
