<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\RecoveryWaiter;
use OutOfBoundsException;

/**
 * RecoveryWaiters - sessions parked on a password-recovery code step, by accept key (HIL-416).
 *
 * Framework-owned state collection; the project registers it and the agent that
 * owns the sign-in surface writes it - a reset request parks a connection here, an
 * accepted code grants the session its password step, a saved password empties the
 * rows of that identifier, and the tick prunes the ones whose connection is gone.
 *
 * It is asked two questions, and they are two because the flow has two axes: an
 * address, which is what a completed reset settles ({@see findAllByIdentifier()}),
 * and a session, which is what a proven code grants ({@see findAllBySessionToken()}).
 *
 * @extends RtStates<RecoveryWaiter>
 */
final class RecoveryWaiters extends RtStates
{
    public const string STATE_CLASS = RecoveryWaiter::class;

    /**
     * @param ?string $acceptKey Waiting connection accept key, or null for a missing optional key
     * @return ?RecoveryWaiter Waiter row, or null when the connection is not parked
     */
    public function get(?string $acceptKey): ?RecoveryWaiter
    {
        /** @var ?RecoveryWaiter $state */
        $state = parent::get($acceptKey);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and for a
     * waiter it usually is, since a connection that never asked for a code is not parked.
     *
     * @param mixed $offset Waiting connection accept key
     * @return RecoveryWaiter Waiter row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): RecoveryWaiter
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Recovery waiter not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Recovery waiter not found: {$offset}");
    }

    /**
     * Every connection parked on one identifier.
     *
     * The converge broadcast's addressing question: one address, however many
     * sessions asked to reset it. Rows are keyed by connection, so this is the only
     * way to ask it, and the answer is a list rather than a row on purpose.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return list<RecoveryWaiter> Waiter rows for the identifier (empty when nobody waits)
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

    /**
     * Every connection of one session parked on a recovery.
     *
     * The grant's addressing question, and the reason session-binding needs no field
     * of its own: a code proven in one tab belongs to the session, so the flip that
     * opens the password step walks the session's rows, and a second device on the
     * same address is not among them.
     *
     * @param string $sessionToken Session token whose parked connections are wanted
     * @return list<RecoveryWaiter> Waiter rows of the session (empty when it parked none)
     */
    public function findAllBySessionToken(string $sessionToken): array
    {
        $waiters = [];
        foreach ($this as $waiter) {
            if ($waiter->sessionToken === $sessionToken) {
                $waiters[] = $waiter;
            }
        }

        return $waiters;
    }
}
