<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * LLMConstants - Default values for LLM providers.
 *
 * Centralized constants for local and external LLM backends.
 */
final class LLMConstants
{
    /** @var float Default request timeout in seconds */
    public const float DEFAULT_TIMEOUT_SEC = 20.0;

    /**
     * @var int Floor a configured request timeout is raised to, in milliseconds
     *
     * Both chat providers hold the same floor, so it has one owner here rather
     * than a private copy in each of them.
     */
    public const int MIN_REQUEST_TIMEOUT_MS = 1000;

    /** @var string Default local (Ollama) base URL */
    public const string DEFAULT_LOCAL_URL = 'http://127.0.0.1:11434';

    /** @var string Default local chat model (Ollama) */
    public const string DEFAULT_LOCAL_CHAT_MODEL = 'qwen2.5:3b';

    /** @var string Default external (OpenAI) base URL */
    public const string DEFAULT_EXTERNAL_URL = 'https://api.openai.com';

    /** @var string Default external chat model */
    public const string DEFAULT_EXTERNAL_CHAT_MODEL = 'gpt-4o-mini';

    /** @var string Provider type: local */
    public const string PROVIDER_LOCAL = 'local';

    /** @var string Provider type: external */
    public const string PROVIDER_EXTERNAL = 'external';
}
