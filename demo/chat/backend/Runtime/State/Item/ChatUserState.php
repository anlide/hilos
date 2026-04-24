<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Actions\Collection\UserStatesActions;
use Hilos\Runtime\State\Item\RtState;

/**
 * Per-user chat runtime row: pending text moderation only.
 *
 * State id is `(string) userId`. Created at chat WebSocket handshake via
 * {@see UserStatesActions::ensure()}, or by {@see UserStatesActions::seedAllFromDb()} at demo startup.
 * Mutations go through {@see UserStatesActions}; file uploads live on {@see Connection}.
 */
final class ChatUserState extends RtState
{
    public const string userId = 'userId';
    public const string moderationMessage = 'moderationMessage';
    public const string moderationUpdatedAt = 'moderationUpdatedAt';

    /** User ID (equals collection key as integer). */
    public private(set) int $userId = 0;

    /** Pending message text for LLM moderation. */
    public string $moderationMessage = '';

    /** Unix time of last moderation field update. */
    public int $moderationUpdatedAt = 0;

    /**
     * @param int $userId Database user id
     * @return static Fresh row with empty moderation fields
     */
    public static function createEmpty(int $userId): static
    {
        $instance = new static();
        $instance->userId = $userId;
        $instance->moderationMessage = '';
        $instance->moderationUpdatedAt = 0;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (string keys: userId, moderationMessage, moderationUpdatedAt)
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->moderationMessage = (string)($row[self::moderationMessage] ?? '');
        $instance->moderationUpdatedAt = (int)($row[self::moderationUpdatedAt] ?? 0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    public static function getRtCollectionKey(): string
    {
        return RtChatContext::userStates;
    }

    /**
     * @param array<string, mixed> $diff Partial update (same string keys as {@see ChatUserState::fromRow()})
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::moderationMessage])) {
            $this->moderationMessage = (string)$diff[self::moderationMessage];
        }
        if (isset($diff[self::moderationUpdatedAt])) {
            $this->moderationUpdatedAt = (int)$diff[self::moderationUpdatedAt];
        }
    }

    /**
     * @return string Runtime collection key (`(string) userId`)
     */
    public function getId(): string
    {
        return (string)$this->userId;
    }

    /**
     * @return array<string, mixed> Row suitable for persistence / truth-source sync
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::moderationMessage => $this->moderationMessage,
            self::moderationUpdatedAt => $this->moderationUpdatedAt,
        ];
    }
}
