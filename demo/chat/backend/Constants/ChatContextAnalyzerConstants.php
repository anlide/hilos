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

    /** @var string OpenAI-compatible response format type key */
    public const string RESPONSE_FORMAT_TYPE = 'type';

    /** @var string OpenAI-compatible JSON object response format */
    public const string RESPONSE_FORMAT_JSON_OBJECT = 'json_object';

    /** @var string Ollama response format key */
    public const string RESPONSE_FORMAT_FORMAT = 'format';

    /** @var string Ollama JSON response format */
    public const string RESPONSE_FORMAT_JSON = 'json';
}
