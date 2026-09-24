<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\View\Actions\Item\HilosOAuthTripActions;

/**
 * Read-only wrapper over one provider sign-in a tab is waiting on (HIL-1044).
 *
 * What the session holder reads to decide where an outcome goes: whom it is owed to, whether it
 * has one already, and - for a grant nobody was listening to - whom it signs in.
 *
 * @extends RtItem<StateHilosOAuthTrip>
 *
 * @property-read string $tripKeyHash Hash of the key the tab minted
 * @property-read string $sessionTokenHash Hash of the session cookie token the trip was started under
 * @property-read string $acceptKey Accept key of the connection the outcome is owed to
 * @property-read string $mode Flow mode of the exchange
 * @property-read string $provider Provider key
 * @property-read ?string $ending How the trip ended, or null while it is still going
 * @property-read ?string $email Colliding address, on the re-authentication ending alone
 * @property-read ?string $linkToken Signed link capability, on the re-authentication ending alone
 * @property-read ?int $userId User a held grant signs in
 * @property-read ?int $sessionId Session row an applied grant signed in
 * @property-read int $updatedAt Epoch milliseconds of the last write
 * @property-read HilosOAuthTripActions $actions Actions for write operations
 */
final class HilosOAuthTrip extends RtItem
{
    /**
     * @param StateHilosOAuthTrip $state Backing runtime state
     */
    public function __construct(StateHilosOAuthTrip $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|HilosOAuthTripActions|null Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     */
    public function __get(string $name): string|int|HilosOAuthTripActions|null
    {
        return match ($name) {
            StateHilosOAuthTrip::tripKeyHash => $this->_state->tripKeyHash,
            StateHilosOAuthTrip::sessionTokenHash => $this->_state->sessionTokenHash,
            StateHilosOAuthTrip::acceptKey => $this->_state->acceptKey,
            StateHilosOAuthTrip::mode => $this->_state->mode,
            StateHilosOAuthTrip::provider => $this->_state->provider,
            StateHilosOAuthTrip::ending => $this->_state->ending,
            StateHilosOAuthTrip::email => $this->_state->email,
            StateHilosOAuthTrip::linkToken => $this->_state->linkToken,
            StateHilosOAuthTrip::userId => $this->_state->userId,
            StateHilosOAuthTrip::sessionId => $this->_state->sessionId,
            StateHilosOAuthTrip::updatedAt => $this->_state->updatedAt,
            RtItem::actions => $this->getItemActions(),
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
