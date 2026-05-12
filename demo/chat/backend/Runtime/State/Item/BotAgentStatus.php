<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime lifecycle status for one bot agent.
 */
final class BotAgentStatus extends RtState
{
    public const string STATUS_JOINED = 'joined';
    public const string STATUS_LEFT = 'left';

    public const string botId = 'botId';
    public const string status = 'status';
    public const string updatedAt = 'updatedAt';

    /** Bot database id. */
    public private(set) int $botId = 0;

    /** Lifecycle marker: joined or left. */
    public string $status = self::STATUS_LEFT;

    /** Unix time when the lifecycle marker changed. */
    public int $updatedAt = 0;

    /**
     * @param int $botId Bot database id
     * @param string $status Lifecycle marker
     * @return static Fresh status row
     */
    public static function create(int $botId, string $status): static
    {
        $instance = new static();
        $instance->botId = $botId;
        $instance->status = self::normalizeStatus($status);
        $instance->updatedAt = time();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->botId = (int)($row[self::botId] ?? 0);
        $instance->status = self::normalizeStatus((string)($row[self::status] ?? self::STATUS_LEFT));
        $instance->updatedAt = (int)($row[self::updatedAt] ?? 0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for bot lifecycle status rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::botAgentStatuses;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::status])) {
            $this->status = self::normalizeStatus((string)$diff[self::status]);
        }
        if (isset($diff[self::updatedAt])) {
            $this->updatedAt = (int)$diff[self::updatedAt];
        }
    }

    /**
     * @return string Runtime row id, `(string) botId`
     */
    public function getId(): string
    {
        return (string)$this->botId;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::botId => $this->botId,
            self::status => $this->status,
            self::updatedAt => $this->updatedAt,
        ];
    }

    private static function normalizeStatus(string $status): string
    {
        return $status === self::STATUS_JOINED ? self::STATUS_JOINED : self::STATUS_LEFT;
    }
}
