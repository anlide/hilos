<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Socket\Server\HttpServer;

/**
 * HttpReplyDestination - Routes an agent's HTTP reply back to the connection this node holds for it.
 *
 * The HTTP twin of {@see CommandReplyDestination}: DaemonManager resolves the held HttpClient in
 * the {@see HttpServer} by this correlation id and writes the reply to it.
 */
final class HttpReplyDestination implements Destination
{
    /**
     * @param string $correlationId Correlation id of the parked HTTP request
     */
    public function __construct(
        public readonly string $correlationId,
    ) {
    }
}
