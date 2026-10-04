<?php

declare(strict_types=1);

namespace Hilos\Socket\Command;

use Hilos\Constants\CommandChannelWindows;

/**
 * Remembers the node holding each command's connection on the node answering it.
 *
 * Replies are built in many agent handlers from only a correlation id, without the request.
 * An entry leaves when its reply is taken, its node leaves, or the requesting daemon's held
 * connection has timed out. Expired entries are removed on the next note or take.
 */
final class CommandReplyOrigins
{
    private const string NODE_ID = 'nodeId';
    private const string NOTED_AT = 'notedAt';

    /** @var array<string, array{nodeId: string, notedAt: float}> */
    private array $origins = [];

    /**
     * @param string $correlationId Command correlation id
     * @param string $nodeId Node holding the connection
     * @param float $now Current time in seconds
     */
    public function note(string $correlationId, string $nodeId, float $now): void
    {
        $this->forgetExpired($now);
        $this->origins[$correlationId] = [self::NODE_ID => $nodeId, self::NOTED_AT => $now];
    }

    /**
     * Takes and forgets the destination of one reply.
     *
     * @param string $correlationId Command correlation id
     * @param float $now Current time in seconds
     * @return ?string Node holding the connection, or null if no live entry exists
     */
    public function take(string $correlationId, float $now): ?string
    {
        $this->forgetExpired($now);
        $nodeId = $this->origins[$correlationId][self::NODE_ID] ?? null;
        unset($this->origins[$correlationId]);

        return $nodeId;
    }

    /**
     * Forgets requests whose console connections left with a cluster member.
     *
     * @param string $nodeId Departed node id
     */
    public function forgetNode(string $nodeId): void
    {
        foreach ($this->origins as $correlationId => $origin) {
            if ($origin[self::NODE_ID] === $nodeId) {
                unset($this->origins[$correlationId]);
            }
        }
    }

    /** @param float $now Current time in seconds */
    private function forgetExpired(float $now): void
    {
        foreach ($this->origins as $correlationId => $origin) {
            if ($now - $origin[self::NOTED_AT] >= CommandChannelWindows::CHANNEL_HELD_SECONDS) {
                unset($this->origins[$correlationId]);
            }
        }
    }
}
