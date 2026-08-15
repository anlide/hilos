<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\RegistrationWaiter;
use OutOfBoundsException;

/**
 * RegistrationWaiters - sessions parked on a registration code step, by accept key (HIL-415).
 *
 * Framework-owned state collection; the project registers it and the agent that
 * owns the sign-in surface writes it - a submit parks a connection here, a
 * confirmation or an expired hold empties the rows of that identifier, and the
 * tick prunes the ones whose connection is gone.
 *
 * @extends RtStates<RegistrationWaiter>
 */
final class RegistrationWaiters extends RtStates
{
    public const string STATE_CLASS = RegistrationWaiter::class;

    /**
     * @param ?string $acceptKey Waiting connection accept key, or null for a missing optional key
     * @return ?RegistrationWaiter Waiter row, or null when the connection is not parked
     */
    public function get(?string $acceptKey): ?RegistrationWaiter
    {
        /** @var ?RegistrationWaiter $state */
        $state = parent::get($acceptKey);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and for a
     * waiter it usually is, since a connection that never submitted is not parked.
     *
     * @param mixed $offset Waiting connection accept key
     * @return RegistrationWaiter Waiter row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): RegistrationWaiter
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Registration waiter not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Registration waiter not found: {$offset}");
    }

    /**
     * Every connection parked on one identifier.
     *
     * The converge broadcast's addressing question: one address, however many
     * sessions typed it. Rows are keyed by connection, so this is the only way to
     * ask it, and the answer is a list rather than a row on purpose.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return list<RegistrationWaiter> Waiter rows for the identifier (empty when nobody waits)
     */
    public function findAllByIdentifier(string $identifier): array
    {
        $waiters = [];
        foreach ($this as $waiter) {
            if ($waiter->identifier === $identifier) {
                $waiters[] = $waiter;
            }
        }

        return $waiters;
    }
}
