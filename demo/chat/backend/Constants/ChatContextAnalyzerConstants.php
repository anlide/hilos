<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatContextAnalyzerConstants - Constants for ChatContextAnalyzerAgent.
 *
 * Constants for chat context analyzer (model, max tokens, events).
 */
final class ChatContextAnalyzerConstants
{
    /** @var int Maximum number of recent events to include in LLM context for summarization */
    public const int MAX_RECENT_EVENTS = 40;

    /** @var int Max tokens for LLM response (topic + summary) */
    public const int MAX_RESPONSE_TOKENS = 512;
}
