<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatContextAnalyzerConstants - Constants for ChatContextAnalyzerAgent
 *
 * Mirrors structure of ModerationEnv/ChatSignalConstants for moderator.
 */
class ChatContextAnalyzerConstants
{
    /** Maximum number of recent events to include in LLM context for summarization */
    public const int MAX_RECENT_EVENTS = 40;

    /** Max tokens for LLM response (topic + summary) */
    public const int MAX_RESPONSE_TOKENS = 512;
}
