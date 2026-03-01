<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatLLMConstants - LLM model names for chat demo
 *
 * Moderator uses lightweight model for low-latency allow/block classification.
 * Bots use heavier model for richer generated responses.
 */
class ChatLLMConstants
{
    /** Lightweight model for moderator (allow/block classification) */
    public const string MODEL_MODERATION = 'qwen2.5:0.5b';

    /** Heavier model for bots (generated messages) */
    public const string MODEL_BOT = 'qwen2.5:3b';
}
