<?php

declare(strict_types=1);

namespace Demo\Chat\Hilos\Runtime\State\Item;

use Hilos\Hilos\Runtime\State\Item\RtState;

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

    public function getId(): string
    {
        return $this->data[self::acceptKey];
    }

    public function getAcceptKey(): string
    {
        return $this->data[self::acceptKey];
    }

    public function getUserId(): int
    {
        return $this->data[self::userId];
    }

    public function getConnectedAt(): int
    {
        return $this->data[self::connectedAt];
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
