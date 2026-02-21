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
 * @property string $acceptKey WebSocket accept key (unique connection identifier)
 * @property int $userId User ID associated with this connection
 * @property int $connectedAt Unix timestamp when connection was established
 */
class Connection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string connectedAt = 'connectedAt';

    private string $acceptKey {
        get {
            return $this->acceptKey;
        }
    }
    private int $userId {
        get {
            return $this->userId;
        }
    }
    private int $connectedAt {
        get {
            return $this->connectedAt;
        }
    }

    public static function create(string $acceptKey, int $userId): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->connectedAt = time();
        return $instance;
    }

    public function getId(): string
    {
        return $this->acceptKey;
    }

    public function __get(string $name): string|int
    {
        return match ($name) {
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::connectedAt => $this->connectedAt,
            default => parent::__get($name),
        };
    }

    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::connectedAt => $this->connectedAt,
        ];
    }
}
