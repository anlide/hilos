<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\InvalidFormatException;
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
    private(set) int $botId = 0;

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
     * @return static Hydrated status row
     * @throws InvalidFormatException When the row is missing a field the status is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->botId = self::requireInt($row, self::botId);
        $instance->status = self::normalizeStatus(self::requireString($row, self::status));
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
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
     * A diff carries only the fields that changed, so an absent key leaves the field
     * as it was; a key that is present is read at its declared type. Wrapping the
     * patched marker in {@see self::normalizeStatus()} is safe either way - the
     * function is idempotent, so a diff about some other field re-normalizes a value
     * that already passed through it.
     *
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->status = self::normalizeStatus(self::patchString($diff, self::status, $this->status));
        $this->updatedAt = self::patchInt($diff, self::updatedAt, $this->updatedAt);
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
