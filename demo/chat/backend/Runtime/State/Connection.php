<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State;

use Hilos\Runtime\State\RtState;

/**
 * Connection state - stores runtime data for a single WebSocket connection
 *
 * Maps acceptKey to userId and additional connection metadata.
 * This is the single source of truth for connection data.
 *
 * @property-read string $acceptKey WebSocket accept key (unique connection identifier)
 * @property-read int $userId User ID associated with this connection
 * @property-read int $connectedAt Unix timestamp when connection was established
 */
class Connection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string connectedAt = 'connectedAt';

    /**
     * Create new connection state
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @return static
     */
    public static function create(string $acceptKey, int $userId): static
    {
        $instance = new static();
        $instance->data = [
            self::acceptKey => $acceptKey,
            self::userId => $userId,
            self::connectedAt => time(),
        ];
        return $instance;
    }

    /**
     * Get unique identifier (acceptKey)
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->data[self::acceptKey];
    }

    /**
     * Get acceptKey
     *
     * @return string
     */
    public function getAcceptKey(): string
    {
        return $this->data[self::acceptKey];
    }

    /**
     * Get userId
     *
     * @return int
     */
    public function getUserId(): int
    {
        return $this->data[self::userId];
    }

    /**
     * Get connection timestamp
     *
     * @return int
     */
    public function getConnectedAt(): int
    {
        return $this->data[self::connectedAt];
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
