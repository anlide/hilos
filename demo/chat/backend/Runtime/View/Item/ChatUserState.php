<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Actions\Item\ChatUserStateActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only {@see RtItem} over {@see StateChatUserState} (mirrors state fields + virtual `user`).
 *
 * @extends RtItem<StateChatUserState>
 *
 * @property-read int $userId User ID
 * @property-read string $outboundModerationRequestId Current moderation request id
 * @property-read string $outboundModerationPhase Current moderation phase
 * @property-read string $outboundModerationMessage Submitted message text
 * @property-read string $outboundModerationAttachmentDraftIdsJson JSON encoded draft ids
 * @property-read string $outboundModerationReason Rejection or unavailable reason
 * @property-read int $outboundModerationUpdatedAt Last moderation update unix time
 * @property-read float $lastOutboundSubmittedAt Last accepted outbound submit microtime
 * @property-read ?User $user User row or null if not found in DB view
 * @property-read ChatUserStateActions $actions Write operations for this user runtime state
 */
final class ChatUserState extends RtItem
{
    /**
     * Minimum interval in seconds between accepted outbound submissions.
     */
    public const int MESSAGE_RATE_LIMIT_SECONDS = 10;

    /**
     * Allowed client-side timer drift before the visible rate-limit window ends.
     */
    public const int MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS = 1;

    /**
     * No visible moderation state.
     */
    public const string OUTBOUND_MODERATION_PHASE_NONE = '';

    /**
     * Moderation phase while an outbound user message is being checked.
     */
    public const string OUTBOUND_MODERATION_PHASE_CHECKING = 'checking';

    /**
     * Moderation phase for a user-retryable rejected message.
     */
    public const string OUTBOUND_MODERATION_PHASE_REJECTED = 'rejected';

    /**
     * Moderation phase for unavailable moderation or missing attachment state.
     */
    public const string OUTBOUND_MODERATION_PHASE_UNAVAILABLE = 'unavailable';

    /**
     * @param StateChatUserState $state Backing state (by reference, same as parent contract)
     */
    public function __construct(StateChatUserState &$state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates known keys to the backing state; virtual `user` loads from the DB users collection.
     *
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared virtual property
     */
    public function __get(string $name): int|float|string|User|null|ChatUserStateActions
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;

        return match ($name) {
            StateChatUserState::userId => $state->userId,
            StateChatUserState::outboundModerationRequestId => $state->outboundModerationRequestId,
            StateChatUserState::outboundModerationPhase => $state->outboundModerationPhase,
            StateChatUserState::outboundModerationMessage => $state->outboundModerationMessage,
            StateChatUserState::outboundModerationAttachmentDraftIdsJson => $state->outboundModerationAttachmentDraftIdsJson,
            StateChatUserState::outboundModerationReason => $state->outboundModerationReason,
            StateChatUserState::outboundModerationUpdatedAt => $state->outboundModerationUpdatedAt,
            StateChatUserState::lastOutboundSubmittedAt => $state->lastOutboundSubmittedAt,
            DbChatContext::user => Hilos::$db->users[$state->userId] ?? null,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row (same as {@see StateChatUserState::toArray()})
     */
    public function toArray(): array
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;

        return $state->toArray();
    }

    /**
     * Whether the user is outside the outbound submit rate-limit window.
     *
     * Uses one second of tolerance to reduce false blocks from client timer drift.
     */
    public function canStartOutboundSubmission(): bool
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;

        return (microtime(true) - $state->lastOutboundSubmittedAt)
            >= self::MESSAGE_RATE_LIMIT_SECONDS - self::MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS;
    }

    /**
     * Whether the user currently has an outbound submit being checked.
     */
    public function hasActiveOutboundModeration(): bool
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;

        return $state->outboundModerationPhase === self::OUTBOUND_MODERATION_PHASE_CHECKING;
    }

    /**
     * Decode current outbound attachment draft ids.
     *
     * @return list<string> Draft ids
     */
    public function getOutboundModerationAttachmentDraftIds(): array
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;
        $decoded = json_decode($state->outboundModerationAttachmentDraftIdsJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $draftIds = [];
        foreach ($decoded as $draftId) {
            if (is_string($draftId) && $draftId !== '') {
                $draftIds[] = $draftId;
            }
        }

        return $draftIds;
    }
}
