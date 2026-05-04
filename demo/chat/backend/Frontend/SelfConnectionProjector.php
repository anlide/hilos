<?php

declare(strict_types=1);

namespace Demo\Chat\Frontend;

use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Demo\Chat\Runtime\View\Item\Connection;

/**
 * Builds the browser-safe current connection payload for the main chat page.
 */
final class SelfConnectionProjector
{
    /**
     * Builds the current connection summary visible to one WebSocket connection.
     *
     * @return array<string, mixed> Browser-safe selfConnection payload
     */
    public static function forConnection(Connection $connection): array
    {
        $messageRateLimitSecondsRemaining = 0;
        if ($connection->userState !== null && $connection->userState->lastOutboundSubmittedAt > 0.0) {
            $messageRateLimitSecondsRemaining = max(
                0,
                (int)ceil(
                    ChatUserState::MESSAGE_RATE_LIMIT_SECONDS
                    - (microtime(true) - $connection->userState->lastOutboundSubmittedAt),
                ),
            );
        }

        $fileUploadProgress = null;
        if ($connection->fileProgressFilename !== null) {
            $fileUploadProgress = [
                SelfConnectionSignalData::filename => $connection->fileProgressFilename,
                SelfConnectionSignalData::uploadedBytes => $connection->fileProgressUploadedBytes,
                SelfConnectionSignalData::totalBytes => $connection->fileProgressTotalBytes,
            ];
        }

        return [
            SelfConnectionSignalData::userId => $connection->userId,
            SelfConnectionSignalData::connectedAt => $connection->connectedAt,
            SelfConnectionSignalData::messageRateLimitSecondsRemaining => $messageRateLimitSecondsRemaining,
            SelfConnectionSignalData::outboundModerationState =>
                OutboundModerationStateProjector::forConnection($connection),
            SelfConnectionSignalData::fileUploadProgress => $fileUploadProgress,
        ];
    }
}
