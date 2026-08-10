<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\State\Collection\HilosSessionConnections as StateHilosSessionConnections;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Item\HilosSessionConnection;

/**
 * Read-only wrapper around the connections runtime state — the session stage (HIL-509).
 *
 * The stage above {@see HilosConnections}: it adds the one read the session-host
 * seam makes, and hands it back as plain accept keys so the seam never touches
 * the RT state layer.
 *
 * @template TItem of HilosSessionConnection
 * @template TActions of HilosConnectionsActions
 * @extends HilosConnections<TItem, TActions>
 */
abstract class HilosSessionConnections extends HilosConnections
{
    /**
     * @return StateHilosSessionConnections Backing state collection
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosSessionConnections
    {
        /** @var StateHilosSessionConnections */
        return parent::getStateCollection();
    }

    /**
     * Accept keys of the live connections belonging to a session token.
     *
     * @param string $sessionToken Session cookie token
     * @return list<string> Accept keys of the token's live connections (empty for an unknown token)
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function acceptKeysForSessionToken(string $sessionToken): array
    {
        return array_keys($this->getStateCollection()->findAllBySessionToken($sessionToken));
    }
}
