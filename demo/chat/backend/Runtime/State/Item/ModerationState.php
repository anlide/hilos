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

    public static function create(int $userId, string $message): static
    {
        $instance = new static();
        $instance->userId = $userId;
        $instance->message = $message;
        $instance->updatedAt = time();
        return $instance;
    }

    public function getId(): string
    {
        return (string)$this->userId;
    }

    public function __get(string $name): int|string
    {
        return match ($name) {
            self::userId => $this->userId,
            self::message => $this->message,
            self::updatedAt => $this->updatedAt,
            default => parent::__get($name),
        };
    }

    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::message => $this->message,
            self::updatedAt => $this->updatedAt,
        ];
    }
}
