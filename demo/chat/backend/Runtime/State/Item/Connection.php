<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Connection state - stores runtime data for a single WebSocket connection.
 *
 * Maps acceptKey to userId and additional connection metadata.
 * This is the single source of truth for connection data.
 *
 * @property string $acceptKey WebSocket accept key (unique connection identifier)
 * @property int $userId User ID associated with this connection
 * @property int $connectedAt Unix timestamp when connection was established
 */
final class Connection extends RtState
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

    /**
     * Create connection state instance.
     *
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param int $userId User ID
     * @return static New instance
     */
    public static function create(string $acceptKey, int $userId): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->connectedAt = time();
        return $instance;
    }

    /**
     * Create instance from row data (e.g. from persistence).
     *
     * @param array<string, mixed> $row Row data with acceptKey, userId, connectedAt
     * @return static New instance
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = (string)($row[self::acceptKey] ?? '');
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->connectedAt = (int)($row[self::connectedAt] ?? time());
        return $instance;
    }

    /**
     * Apply diff to state (partial update).
     *
     * @param array<string, mixed> $diff Fields to update (userId, connectedAt)
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::userId])) {
            $this->userId = (int)$diff[self::userId];
        }
        if (isset($diff[self::connectedAt])) {
            $this->connectedAt = (int)$diff[self::connectedAt];
        }
    }

    /**
     * Get state ID (accept key).
     *
     * @return string Accept key
     */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * Get property value by name (acceptKey, userId, connectedAt).
     *
     * @param string $name Property name
     * @return string|int Property value
     * @throws RtStatePropertyNotFoundException If property name is invalid
     */
    public function __get(string $name): string|int
    {
        return match ($name) {
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::connectedAt => $this->connectedAt,
            default => parent::__get($name),
        };
    }

    /**
     * Convert state to associative array for persistence/serialization.
     *
     * @return array<string, mixed> State data (acceptKey, userId, connectedAt)
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::connectedAt => $this->connectedAt,
        ];
    }
}
