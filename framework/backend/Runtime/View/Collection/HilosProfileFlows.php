<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosProfileFlows as StateHilosProfileFlows;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosProfileFlowsActions;
use Hilos\Runtime\View\Item\HilosProfileFlow;

/**
 * Read-only wrapper around the profile windows half-way through (HIL-1182).
 *
 * Framework-owned on both halves and mounted by the sign-in feature. It is read in two places: by
 * the users library, which lets a step through only on what an earlier step of the same session
 * proved, and by the session holder, which puts a session's flows on the wire. Its writes belong
 * to the holder alone; the library reports every step by frame.
 *
 * @extends RtCollection<HilosProfileFlow, HilosProfileFlowsActions>
 * @property-read HilosProfileFlowsActions $actions Actions for write operations
 */
final class HilosProfileFlows extends RtCollection
{
    /**
     * Every flow one session has going - at most one per window.
     *
     * What the tabs of that session are told, whole, on every move and on every handshake.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @return list<HilosProfileFlow> Flows of the session (empty when it has none)
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function forSessionTokenHash(string $sessionTokenHash): array
    {
        $flows = [];
        foreach ($this->getStateCollection() as $state) {
            if ($state->sessionTokenHash !== $sessionTokenHash) {
                continue;
            }

            $flow = $this->offsetGet($state->getId());
            if ($flow !== null) {
                $flows[] = $flow;
            }
        }

        return $flows;
    }

    /**
     * @return StateHilosProfileFlows Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosProfileFlows
    {
        /** @var StateHilosProfileFlows */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosProfileFlow instance
     * @return HilosProfileFlow View item for this flow
     */
    protected function createRtItem(RtState $state): HilosProfileFlow
    {
        /** @var StateHilosProfileFlow $state */
        return new HilosProfileFlow($state);
    }

    /**
     * @param mixed $offset Row id ({@see StateHilosProfileFlow::idFor()})
     * @return ?HilosProfileFlow Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosProfileFlow
    {
        /** @var ?HilosProfileFlow $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosProfileFlowsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosProfileFlowsActions
    {
        /** @var HilosProfileFlowsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosProfileFlowsActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosProfileFlowsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
