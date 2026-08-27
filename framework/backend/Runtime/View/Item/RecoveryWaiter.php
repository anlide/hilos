<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\View\Actions\Collection\RecoveryWaitersActions;

/**
 * Read-only wrapper over one session parked on a password-recovery code step (HIL-416).
 *
 * What the recovery flow reads off a waiter: where to send a step change, which
 * session the grant belongs to, and whether that session has already proven the
 * code. The row carries no write API of its own - a waiter is parked, granted and
 * released, and all three are collection-level acts on
 * {@see RecoveryWaitersActions}, because each of them addresses a set (an address,
 * a session) rather than this one row.
 *
 * @extends RtItem<StateRecoveryWaiter>
 *
 * @property-read string $acceptKey Accept key of the waiting connection
 * @property-read string $identifier Identifier whose reset code is awaited
 * @property-read string $sessionToken Session token the grant is bound to
 * @property-read bool $codeAccepted Whether this session may already save a new password
 */
final class RecoveryWaiter extends RtItem
{
    /**
     * @param StateRecoveryWaiter $state Backing runtime state
     */
    public function __construct(StateRecoveryWaiter $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|bool Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     */
    public function __get(string $name): string|bool
    {
        return match ($name) {
            StateRecoveryWaiter::acceptKey => $this->_state->acceptKey,
            StateRecoveryWaiter::identifier => $this->_state->identifier,
            StateRecoveryWaiter::sessionToken => $this->_state->sessionToken,
            StateRecoveryWaiter::codeAccepted => $this->_state->codeAccepted,
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
