<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Core\Router\DTO\AttachmentDraftSignalData;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Demo\Chat\Runtime\View\Item\Connection;

/**
 * Builds the connection-local outbound moderation payload used by the chat composer.
 */
final class OutboundModerationStateProjector
{
    /**
     * Builds the moderation payload visible to one WebSocket connection.
     *
     * @return ?array<string, mixed> Moderation UI payload or null
     */
    public static function forConnection(Connection $connection): ?array
    {
        $userState = $connection->userState;
        if (
            $userState === null
            || $userState->outboundModerationAcceptKey !== $connection->acceptKey
        ) {
            return null;
        }

        return self::forUserState($userState, $connection);
    }

    /**
     * Builds the moderation payload for the state target connection.
     *
     * @return ?array<string, mixed> Moderation UI payload or null
     */
    private static function forUserState(ChatUserState $state, Connection $connection): ?array
    {
        if (
            $state->outboundModerationAcceptKey === ''
            || $state->outboundModerationPhase === ChatUserState::OUTBOUND_MODERATION_PHASE_NONE
        ) {
            return null;
        }

        $drafts = [];
        foreach ($state->getOutboundModerationAttachmentDraftIds() as $draftId) {
            foreach ($connection->attachmentDrafts as $draft) {
                if ($draft->draftId !== $draftId) {
                    continue;
                }

                $drafts[] = $draft;
                break;
            }
        }

        return [
            'requestId' => $state->outboundModerationRequestId,
            'phase' => $state->outboundModerationPhase,
            'text' => $state->outboundModerationMessage,
            'attachments' => AttachmentDraftSignalData::listFromDraftItems(...$drafts),
            'reason' => $state->outboundModerationReason !== '' ? $state->outboundModerationReason : null,
            'updatedAt' => $state->outboundModerationUpdatedAt,
        ];
    }
}
