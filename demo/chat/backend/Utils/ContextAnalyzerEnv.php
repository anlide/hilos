<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Utils\Env;

/**
 * ContextAnalyzerEnv - Reads context analyzer LLM config from env via Env
 *
 * Used by ChatContextAnalyzerAgent for summarization and topic extraction.
 */
class ContextAnalyzerEnv
{
    /**
     * Get LLM model name for context analyzer.
     *
     * @return string Model name
     */
    public static function getModel(): string
    {
        return Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL, ChatLLMConstants::MODEL_CONTEXT_ANALYZER);
    }

    /**
     * Get LLM API base URL (Ollama or external).
     *
     * @return string Base URL without trailing slash
     */
    public static function getUrl(): string
    {
        $url = trim(Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_URL, ''));
        if ($url === '') {
            $url = Env::getFilled(EnvConstants::LLM_LOCAL_URL, LLMConstants::DEFAULT_LOCAL_URL);
        }
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/api/generate')) {
            $url = substr($url, 0, -strlen('/api/generate'));
        }

        return $url;
    }

    /**
     * Get timeout in seconds for LLM API calls.
     *
     * @return float Timeout in seconds
     */
    public static function getTimeoutSec(): float
    {
        $timeout = Env::getFloat(EnvConstants::CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC);

        return $timeout > 0 ? $timeout : LLMConstants::DEFAULT_TIMEOUT_SEC;
    }

    /**
     * Whether to use external LLM provider instead of local Ollama.
     *
     * @return bool True if external provider should be used
     */
    public static function useExternalProvider(): bool
    {
        $value = Env::getFilled(EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER, 'local');

        return strtolower($value) === 'external';
    }
}
