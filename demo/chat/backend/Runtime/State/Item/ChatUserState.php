<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Actions\Collection\UserStatesActions;
use Hilos\Runtime\State\Item\RtState;

/**
 * Per-user chat runtime row: outbound moderation and message rate-limit state.
 *
 * State id is `(string) userId`. Created lazily at chat WebSocket handshake via
 * {@see UserStatesActions::ensure()}.
 * Mutations go through {@see UserStatesActions}; binary upload sessions live on {@see Connection}.
 */
final class ChatUserState extends RtState
{
    public const string userId = 'userId';
    public const string outboundModerationRequestId = 'outboundModerationRequestId';
    public const string outboundModerationPhase = 'outboundModerationPhase';
    public const string outboundModerationMessage = 'outboundModerationMessage';
    public const string outboundModerationAttachmentDraftIdsJson = 'outboundModerationAttachmentDraftIdsJson';
    public const string outboundModerationReason = 'outboundModerationReason';
    public const string outboundModerationUpdatedAt = 'outboundModerationUpdatedAt';
    public const string lastOutboundSubmittedAt = 'lastOutboundSubmittedAt';

    /** User ID (equals collection key as integer). */
    public private(set) int $userId = 0;

    /** Current moderation request id, or empty string when no visible moderation state exists. */
    public string $outboundModerationRequestId = '';

    /** Moderation phase: checking, rejected, unavailable, or empty when clear. */
    public string $outboundModerationPhase = '';

    /** Submitted message text associated with the moderation state. */
    public string $outboundModerationMessage = '';

    /** JSON encoded list of submitted attachment draft ids. */
    public string $outboundModerationAttachmentDraftIdsJson = '[]';

    /** Moderation rejection or unavailable reason. */
    public string $outboundModerationReason = '';

    /** Unix time of last moderation state update. */
    public int $outboundModerationUpdatedAt = 0;

    /** Microtime of the last accepted outbound submit. */
    public float $lastOutboundSubmittedAt = 0.0;

    /**
     * @param int $userId Database user id
     * @return static Fresh row with empty moderation fields
     */
    public static function createEmpty(int $userId): static
    {
        $instance = new static();
        $instance->userId = $userId;
        $instance->outboundModerationRequestId = '';
        $instance->outboundModerationPhase = '';
        $instance->outboundModerationMessage = '';
        $instance->outboundModerationAttachmentDraftIdsJson = '[]';
        $instance->outboundModerationReason = '';
        $instance->outboundModerationUpdatedAt = 0;
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
        $instance->outboundModerationRequestId = (string)($row[self::outboundModerationRequestId] ?? '');
        $instance->outboundModerationPhase = (string)($row[self::outboundModerationPhase] ?? '');
        $instance->outboundModerationMessage = (string)($row[self::outboundModerationMessage] ?? '');
        $instance->outboundModerationAttachmentDraftIdsJson = (string)($row[self::outboundModerationAttachmentDraftIdsJson] ?? '[]');
        $instance->outboundModerationReason = (string)($row[self::outboundModerationReason] ?? '');
        $instance->outboundModerationUpdatedAt = (int)($row[self::outboundModerationUpdatedAt] ?? 0);
        $instance->lastOutboundSubmittedAt = (float)($row[self::lastOutboundSubmittedAt] ?? 0.0);
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
        if (isset($diff[self::outboundModerationRequestId])) {
            $this->outboundModerationRequestId = (string)$diff[self::outboundModerationRequestId];
        }
        if (isset($diff[self::outboundModerationPhase])) {
            $this->outboundModerationPhase = (string)$diff[self::outboundModerationPhase];
        }
        if (isset($diff[self::outboundModerationMessage])) {
            $this->outboundModerationMessage = (string)$diff[self::outboundModerationMessage];
        }
        if (isset($diff[self::outboundModerationAttachmentDraftIdsJson])) {
            $this->outboundModerationAttachmentDraftIdsJson = (string)$diff[self::outboundModerationAttachmentDraftIdsJson];
        }
        if (isset($diff[self::outboundModerationReason])) {
            $this->outboundModerationReason = (string)$diff[self::outboundModerationReason];
        }
        if (isset($diff[self::outboundModerationUpdatedAt])) {
            $this->outboundModerationUpdatedAt = (int)$diff[self::outboundModerationUpdatedAt];
        }
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
            self::outboundModerationRequestId => $this->outboundModerationRequestId,
            self::outboundModerationPhase => $this->outboundModerationPhase,
            self::outboundModerationMessage => $this->outboundModerationMessage,
            self::outboundModerationAttachmentDraftIdsJson => $this->outboundModerationAttachmentDraftIdsJson,
            self::outboundModerationReason => $this->outboundModerationReason,
            self::outboundModerationUpdatedAt => $this->outboundModerationUpdatedAt,
            self::lastOutboundSubmittedAt => $this->lastOutboundSubmittedAt,
        ];
    }
}
