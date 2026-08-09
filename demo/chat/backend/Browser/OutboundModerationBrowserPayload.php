<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Runtime\View\Item\Connection;

/**
 * Builds the connection-local outbound moderation payload used by the chat composer.
 */
final class OutboundModerationBrowserPayload
{
    /**
     * Builds the moderation payload visible to one WebSocket connection.
     *
     * @return ?array<string, mixed> Moderation UI payload or null
     */
    public static function forConnection(Connection $connection): ?array
    {
        $text = $connection->outboundModerationMessage;
        if (
            $connection->outboundModerationPhase
            === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE
            || $text === null
        ) {
            return null;
        }

        return [
            'phase' => $connection->outboundModerationPhase,
            'text' => $text,
            'reason' => $connection->outboundModerationReason,
            'updatedAt' => $connection->outboundModerationUpdatedAt,
        ];
    }
}
