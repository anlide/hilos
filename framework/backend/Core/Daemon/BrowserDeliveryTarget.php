<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;

/** Exact local transport and address incarnation found through the server index. */
final readonly class BrowserDeliveryTarget
{
    /**
     * @param WebSocketServer $server Original owning server
     * @param int $slot Original roster slot
     * @param WebSocketClient $client Original connection
     * @param int $generation Original key incarnation on that slot
     */
    public function __construct(
        private WebSocketServer $server,
        private int $slot,
        private WebSocketClient $client,
        private int $generation,
    ) {
    }

    /** @return ?WebSocketClient Original connection, absent after it leaves or starts closing */
    public function getClient(): ?WebSocketClient
    {
        return $this->server->isCurrentClientTarget($this->slot, $this->client, $this->generation) ? $this->client : null;
    }
}
