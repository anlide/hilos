<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatLLMConstants - LLM model names for chat demo.
 *
 * Moderator uses lightweight model for low-latency allow/block classification.
 * Context analyzer uses lightweight model for topic/summary extraction.
 * Bots use heavier model for richer generated responses.
 */
final class ChatLLMConstants
{
    /** @var string Lightweight model for moderator (allow/block classification) */
    public const string MODEL_MODERATION = 'qwen2.5:0.5b';

    /** @var string Heavier model for context analyzer (topic/summary extraction, richer analysis) */
    public const string MODEL_CONTEXT_ANALYZER = 'qwen2.5:7b';

    /** @var string Heavier model for bots (generated messages) */
    public const string MODEL_BOT = 'qwen2.5:3b';

    /** @var string LLM profile key for bot message generation */
    public const string PROFILE_BOT = 'chat.bot';

    /** @var string LLM profile key for moderation */
    public const string PROFILE_MODERATION = 'chat.moderation';

    /** @var string LLM profile key for context analysis */
    public const string PROFILE_ANALYZER = 'chat.analyzer';
}
