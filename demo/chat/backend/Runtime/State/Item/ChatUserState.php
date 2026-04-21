<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Actions\Collection\UserStatesActions;
use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Per-user chat runtime row: pending text moderation only.
 *
 * State id is `(string) userId`. Created at chat WebSocket handshake via
 * {@see UserStatesActions::ensure()}, or by {@see UserStatesActions::seedAllFromDb()} at demo startup.
 * Mutations go through {@see UserStatesActions}; file uploads live on {@see Connection}.
 *
 * @property int $userId User ID (same as numeric id in {@see self::getId()})
 * @property string $moderationMessage Message text awaiting moderation (empty when none)
 * @property int $moderationUpdatedAt Unix time of last change to moderation fields
 */
final class ChatUserState extends RtState
{
    public const string userId = 'userId';
    public const string moderationMessage = 'moderationMessage';
    public const string moderationUpdatedAt = 'moderationUpdatedAt';

    /** @var int User ID (equals collection key as integer) */
    private int $userId = 0;

    /** @var string Pending message text for LLM moderation */
    private string $moderationMessage = '';

    /** @var int Unix time of last moderation field update */
    private int $moderationUpdatedAt = 0;

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
     * Read-only access for external code; private fields resolve through this magic getter.
     *
     * @throws RtStatePropertyNotFoundException When $name is not a declared field
     */
    public function __get(string $name): int|string
    {
        return match ($name) {
            self::userId => $this->userId,
            self::moderationMessage => $this->moderationMessage,
            self::moderationUpdatedAt => $this->moderationUpdatedAt,
            default => parent::__get($name),
        };
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
