<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Hilos\Runtime\State\Item\RtState;

/**
 * ModerationState state - pending moderation message by user.
 *
 * Stores one in-flight moderation message per user.
 * ID is userId as string.
 *
 * @property int $userId User ID
 * @property string $message Message currently being moderated
 * @property int $updatedAt Unix timestamp when state was updated
 */
class ModerationState extends RtState
{
    public const string userId = 'userId';
    public const string message = 'message';
    public const string updatedAt = 'updatedAt';

    private int $userId {
        get {
            return $this->userId;
        }
    }

    private string $message {
        get {
            return $this->message;
        }
    }

    private int $updatedAt {
        get {
            return $this->updatedAt;
        }
    }

    /**
     * Create state from user ID and message.
     *
     * @param int $userId User ID
     * @param string $message Message currently being moderated
     * @return static New ModerationState instance
     */
    public static function create(int $userId, string $message): static
    {
        $instance = new static();
        $instance->userId = $userId;
        $instance->message = $message;
        $instance->updatedAt = time();
        return $instance;
    }

    /**
     * Create state from row data (e.g. deserialized).
     *
     * @param array<string, mixed> $row Row data with userId, message, updatedAt
     * @return static New ModerationState instance
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->message = (string)($row[self::message] ?? '');
        $instance->updatedAt = (int)($row[self::updatedAt] ?? time());
        return $instance;
    }

    /**
     * Apply partial diff to state (message, updatedAt).
     *
     * @param array<string, mixed> $diff Fields to update
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::message])) {
            $this->message = (string)$diff[self::message];
        }
        if (isset($diff[self::updatedAt])) {
            $this->updatedAt = (int)$diff[self::updatedAt];
        }
    }

    /**
     * Get state ID (userId as string).
     *
     * @return string User ID as string
     */
    public function getId(): string
    {
        return (string)$this->userId;
    }

    /**
     * Magic getter for property access.
     *
     * @param string $name Property name (userId, message, updatedAt)
     * @return int|string Property value
     */
    public function __get(string $name): int|string
    {
        return match ($name) {
            self::userId => $this->userId,
            self::message => $this->message,
            self::updatedAt => $this->updatedAt,
            default => parent::__get($name),
        };
    }

    /**
     * Convert state to array for serialization.
     *
     * @return array<string, mixed> State as associative array
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::message => $this->message,
            self::updatedAt => $this->updatedAt,
        ];
    }
}
