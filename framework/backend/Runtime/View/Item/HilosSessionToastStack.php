<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Actions\Collection\HilosSessionToastStacksActions;

/**
 * Read-only wrapper over one session's toast stack (HIL-768).
 *
 * What the agent reads when it has to put the stack on the wire: the cards themselves, and the
 * tabs that say they are reading them. The row carries no write API of its own - every write
 * is a judgement over the whole list ({@see HilosSessionToastStacksActions}), and the one that
 * matters most, the removal rule, has to weigh a card's expiries against the row's readers.
 *
 * @extends RtItem<StateHilosSessionToastStack>
 *
 * @property-read string $sessionTokenHash Hash of the session cookie token these toasts are addressed to
 * @property-read list<array{key: string, message: string, severity: string, source: string,
 *     destination: string, repeats: int, expiredBy: list<string>}> $toasts Cards the session is still owed
 * @property-read list<string> $readingBy Accept keys of the tabs reading the stack right now
 */
final class HilosSessionToastStack extends RtItem
{
    /**
     * @param StateHilosSessionToastStack $state Backing runtime state
     */
    public function __construct(StateHilosSessionToastStack $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|array<int, mixed> Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     */
    public function __get(string $name): string|array
    {
        return match ($name) {
            StateHilosSessionToastStack::sessionTokenHash => $this->_state->sessionTokenHash,
            StateHilosSessionToastStack::toasts => $this->_state->toasts,
            StateHilosSessionToastStack::readingBy => $this->_state->readingBy,
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
