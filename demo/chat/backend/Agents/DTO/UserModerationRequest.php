<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

/**
 * Runtime-discovered outbound user moderation request.
 */
final readonly class UserModerationRequest
{
    /**
     * Creates a user moderation request from connection-local runtime state.
     *
     * @param string $requestId Moderation request id
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @param string $message Original message text
     */
    public function __construct(
        public string $requestId,
        public string $acceptKey,
        public int $userId,
        public string $message,
    ) {
    }
}
