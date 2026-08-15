<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Database\Object\Collection\AuthBlocks as ObjectAuthBlocks;
use Hilos\Runtime\State\Item\AuthAttempt;
use OutOfBoundsException;

/**
 * AuthAttempts - hot window counters for throttle keys, keyed by `scope|identity|action` (HIL-420).
 *
 * Framework-owned state collection; the throttle service records and mutates
 * counters here on the hot auth path and syncs them across workers, so counters
 * are never round-tripped through the database. Durable blocks live separately
 * in `hilos_auth_block` ({@see ObjectAuthBlocks}).
 *
 * @extends RtStates<AuthAttempt>
 */
final class AuthAttempts extends RtStates
{
    public const string STATE_CLASS = AuthAttempt::class;

    /**
     * @param ?string $key Throttle key `scope|identity|action`, or null for a missing optional key
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
     * @param mixed $offset Throttle key `scope|identity|action`
     * @return AuthAttempt Attempt counter
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): AuthAttempt
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Auth attempt counter not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Auth attempt counter not found: {$offset}");
    }
}
