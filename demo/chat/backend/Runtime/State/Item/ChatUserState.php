<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Runtime\State\Item\RtState;

/**
 * Per-user chat runtime row: shared outbound message rate-limit state.
 *
 * State id is `(string) userId`. Created lazily at chat WebSocket handshake via
 * `UserStatesActions::ensure()`. Connection-local moderation and binary upload sessions live on connections.
 */
final class ChatUserState extends RtState
{
    public const string userId = 'userId';
    public const string lastOutboundSubmittedAt = 'lastOutboundSubmittedAt';

    /** User ID (equals collection key as integer). */
    private(set) int $userId = 0;

    /** Microtime of the last accepted outbound submit. */
    public float $lastOutboundSubmittedAt = 0.0;

    /**
     * @param int $userId Database user id
     * @return static Fresh row with no accepted outbound submit
     */
    public static function createEmpty(int $userId): static
    {
        $instance = new static();
        $instance->userId = $userId;
        $instance->lastOutboundSubmittedAt = 0.0;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->lastOutboundSubmittedAt = (float)($row[self::lastOutboundSubmittedAt] ?? 0.0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for per-user state rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::userStates;
    }

    /**
     * @param array<string, mixed> $diff Partial update using the same string keys as `fromRow()`
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::lastOutboundSubmittedAt])) {
            $this->lastOutboundSubmittedAt = (float)$diff[self::lastOutboundSubmittedAt];
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
            self::lastOutboundSubmittedAt => $this->lastOutboundSubmittedAt,
        ];
    }
}
