<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Core\Exception\InvalidFormatException;
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
     * @return static Hydrated status row
     * @throws InvalidFormatException When the row is missing a field the status row is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->agentId = self::requireString($row, self::agentId);
        $instance->status = self::normalizeStatus(self::requireString($row, self::status));
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
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
     * A diff carries only the fields that changed, so an absent key means the
     * field was not touched; a key that is present is read at its declared type.
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
