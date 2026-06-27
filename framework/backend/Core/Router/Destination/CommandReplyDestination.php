<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

/**
 * CommandReplyDestination - Routes a command reply back to the held CLI
 * connection that originated the request, addressed by correlation id.
 *
 * DaemonManager resolves the held CommandClient in the CommandServer by this
 * correlation id and writes the reply to it.
 */
final class CommandReplyDestination implements Destination
{
    /**
     * @param string $correlationId Correlation id of the originating command request
     */
    public function __construct(
        public readonly string $correlationId,
    ) {
    }
}
