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

    /**
     * The named sockets widened by the rest of the session's, as this register knows them.
     *
     * What a frame about a SESSION has to be answered to. The library that sends such a frame
     * lists the sockets from its own copy of this register, and that copy trails the project's
     * by however long a runtime sync takes - the project settles a socket the moment it
     * handshakes, the library learns of it afterwards. The gap is ordinarily invisible, because
     * a session's state is restated often enough for the next frame to catch up.
     *
     * It stops being invisible for state that is lowered exactly ONCE. A tab whose socket
     * survived a token rotation and came back is answered by the project before the library
     * lists it, so a single frame sent in that window - the success mark being cleared
     * (HIL-875) - never reaches it, and a live socket asks for no second handshake to find out.
     * Widening here closes that window at the one place that cannot be behind: the register's
     * owner.
     *
     * The named keys come first and keep their order, because a frame's initiator is read off
     * the head of its list, and a handshake names a socket this register does not hold yet.
     *
     * @param list<string> $acceptKeys Accept keys the frame names
     * @param string $sessionToken Session cookie token the frame is about
     * @return list<string> The named keys, then every other live socket of that session
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function acceptKeysForSessionFrame(array $acceptKeys, string $sessionToken): array
    {
        foreach ($this->acceptKeysForSessionToken($sessionToken) as $acceptKey) {
            if (!in_array($acceptKey, $acceptKeys, true)) {
                $acceptKeys[] = $acceptKey;
            }
        }

        return $acceptKeys;
    }
}
