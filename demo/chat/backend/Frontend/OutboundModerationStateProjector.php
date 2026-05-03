<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Core\Router\DTO\AttachmentDraftSignalData;
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
        if (
            $connection->outboundModerationRequestId === ''
            || $connection->outboundModerationPhase === Connection::OUTBOUND_MODERATION_PHASE_NONE
        ) {
            return null;
        }

        $drafts = [];
        foreach ($connection->outboundModerationAttachmentDraftIds as $draftId) {
            foreach ($connection->attachmentDrafts as $draft) {
                if ($draft->draftId !== $draftId) {
                    continue;
                }

                $drafts[] = $draft;
                break;
            }
        }

        return [
            'requestId' => $connection->outboundModerationRequestId,
            'phase' => $connection->outboundModerationPhase,
            'text' => $connection->outboundModerationMessage,
            'attachments' => AttachmentDraftSignalData::listFromDraftItems(...$drafts),
            'reason' => $connection->outboundModerationReason !== '' ? $connection->outboundModerationReason : null,
            'updatedAt' => $connection->outboundModerationUpdatedAt,
        ];
    }
}
