<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * LLMConstants - Default values for LLM providers
 *
 * Centralized constants for local and external LLM backends.
 */
class LLMConstants
{
    /** Default request timeout in seconds */
    public const float DEFAULT_TIMEOUT_SEC = 20.0;

    /** Default local (Ollama) base URL */
    public const string DEFAULT_LOCAL_URL = 'http://127.0.0.1:11434';

    /** Default local chat model (Ollama) */
    public const string DEFAULT_LOCAL_CHAT_MODEL = 'qwen2.5:3b';

    /** Default local image model (placeholder until implemented) */
    public const string DEFAULT_LOCAL_IMAGE_MODEL = '';

    /** Default external (OpenAI) base URL */
    public const string DEFAULT_EXTERNAL_URL = 'https://api.openai.com';

    /** Default external chat model */
    public const string DEFAULT_EXTERNAL_CHAT_MODEL = 'gpt-4o-mini';

    /** Default external image model */
    public const string DEFAULT_EXTERNAL_IMAGE_MODEL = 'dall-e-3';

    /** Provider type: local */
    public const string PROVIDER_LOCAL = 'local';

    /** Provider type: external */
    public const string PROVIDER_EXTERNAL = 'external';
}
