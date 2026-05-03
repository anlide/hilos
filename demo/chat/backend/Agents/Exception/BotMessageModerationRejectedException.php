<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Exception;

use Hilos\Core\Agent\Exception\AgentException;

/**
 * Exception thrown when a moderated bot message is rejected.
 */
final class BotMessageModerationRejectedException extends AgentException
{
    /**
     * Creates exception for a rejected bot message moderation result.
     *
     * @param int $botId Bot identifier
     * @param string $reason Moderation rejection reason
     */
    public function __construct(int $botId, string $reason)
    {
        $messageReason = $reason !== '' ? $reason : 'unknown';

        parent::__construct("Bot message blocked by moderation (botId={$botId}; reason={$messageReason})");
    }
}
