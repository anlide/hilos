<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Core\Router\GroupSubscription;
use Hilos\Core\Router\PageSubscription;

/**
 * WebSocketDestination - Routes a signal to a single WebSocket client by accept key.
 */
final class WebSocketDestination implements Destination
{
    /**
     * @param string $acceptKey Target client accept key
     * @param PageSubscription|GroupSubscription|null $subscription Exact local subscription captured by a lazy master walk
     * @param ?string $group Full group name, present only for a captured group membership
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly PageSubscription|GroupSubscription|null $subscription = null,
        public readonly ?string $group = null,
    ) {
    }
}
