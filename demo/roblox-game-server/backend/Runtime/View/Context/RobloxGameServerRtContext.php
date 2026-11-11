<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Runtime\View\Context;

use Demo\RobloxGameServer\Runtime\State\Collection\Connections as StateConnections;
use Demo\RobloxGameServer\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\RobloxGameServer\Runtime\View\Actions\Item\ConnectionActions;
use Demo\RobloxGameServer\Runtime\View\Collection\Connections;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;

/**
 * RobloxGameServerRtContext - roblox-game-server runtime context.
 *
 * Holds the single runtime collection the demo needs: active WebSocket
 * connections, the register the page gate reads a subscriber's identity from.
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);
 *   Hilos::$rt->connections->summaryForUser($userId);
 *
 * @property-read Connections $connections Active connections collection
 */
final class RobloxGameServerRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Registers the connections runtime collection and its view representation.
     *
     * @throws StateCollectionNotFoundException When a represented collection key is not registered
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = StateConnections::init();

        $this->setRepresent(
            self::connections,
            Connections::class,
            ConnectionsActions::class,
            ConnectionActions::class,
        );
    }
}
