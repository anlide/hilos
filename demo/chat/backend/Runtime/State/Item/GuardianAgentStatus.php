<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime UI status for one Hilos guardian agent run.
 */
final class GuardianAgentStatus extends RtState
{
    public const string agentId = 'agentId';
    public const string status = 'status';
    public const string updatedAt = 'updatedAt';

    /** Guardian agent identifier. */
    private(set) string $agentId = '';

    /** Guardian run status value. */
    public string $status = GuardianRunStatus::NOT_STARTED->value;

    /** Unix time when the status changed. */
    public int $updatedAt = 0;

    /**
     * @param string $agentId Guardian agent identifier
     * @param GuardianRunStatus $status Guardian run status
     * @return static Fresh status row
     */
    public static function create(string $agentId, GuardianRunStatus $status): static
    {
        $instance = new static();
        $instance->agentId = $agentId;
        $instance->status = $status->value;
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
        $instance->agentId = (string)($row[self::agentId] ?? '');
        $instance->status = self::normalizeStatus((string)($row[self::status] ?? ''));
        $instance->updatedAt = (int)($row[self::updatedAt] ?? 0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for guardian status rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::guardianAgentStatuses;
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
     * @return string Runtime row id, the guardian agent id
     */
    public function getId(): string
    {
        return $this->agentId;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::agentId => $this->agentId,
            self::status => $this->status,
            self::updatedAt => $this->updatedAt,
        ];
    }

    private static function normalizeStatus(string $status): string
    {
        return GuardianRunStatus::tryFrom($status)?->value ?? GuardianRunStatus::NOT_STARTED->value;
    }
}
