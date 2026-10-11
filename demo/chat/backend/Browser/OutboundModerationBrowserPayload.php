<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\HilosException;

/**
 * Builds the connection-local outbound moderation payload used by the chat composer.
 */
final class OutboundModerationBrowserPayload
{
    /**
     * Builds the moderation payload visible to one WebSocket connection.
     *
     * @return ?array<string, mixed> Moderation UI payload or null
     * @throws HilosException When the refused picture's upload cannot be read
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

        $reason = $connection->outboundModerationReason;
        if (
            $connection->outboundModerationPhase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED
            && $connection->outboundModerationPicture !== null
        ) {
            $reason = ModerationDecision::publicPictureReason(
                $reason,
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED,
                ...self::pictureOf($connection),
            );
        } elseif ($connection->outboundModerationPhase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED) {
            $reason = ModerationDecision::publicMessageReason(
                $reason,
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED,
            );
        } elseif ($connection->outboundModerationPhase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE) {
            // Keep the already-readable file registry refusal if present, otherwise format unavailable message.
            if ($reason === null || in_array($reason, ['', 'service_unavailable', 'unknown'], true)) {
                $reason = ModerationDecision::publicMessageReason(
                    $reason,
                    ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                );
            }
        }

        return [
            'phase' => $connection->outboundModerationPhase,
            'text' => $text,
            'reason' => $reason,
            'updatedAt' => $connection->outboundModerationUpdatedAt,
        ];
    }

    /**
     * Place of the refused picture among every attachment, and the upload's file name.
     *
     * @return array{0: int, 1: ?string} 1-based position, and the file name or null when the upload is gone
     * @throws HilosException When the upload row cannot be read
     */
    public static function pictureOf(Connection $connection): array
    {
        $pictureId = $connection->outboundModerationPicture;
        $position = $pictureId === null
            ? false
            : array_search($pictureId, $connection->outboundModerationAttachments, true);

        return [
            is_int($position) ? $position + 1 : 0,
            $pictureId === null ? null : Hilos::$rt->hilosUploads->find($connection->acceptKey, $pictureId)?->filename,
        ];
    }
}
