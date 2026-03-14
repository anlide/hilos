<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Utils\Env;

/**
 * BotEnv - Reads bot LLM config from env via Env.
 *
 * Used by BotAgent for async message generation.
 */
class BotEnv
{
    /**
     * Get LLM model name for bot chat generation.
     *
     * @return string Model name
     */
    public static function getModel(): string
    {
        return Env::getFilled(EnvConstants::CHAT_BOT_MODEL, ChatLLMConstants::MODEL_BOT);
    }

    /**
     * Get LLM API base URL (Ollama or external).
     *
     * @return string Base URL without trailing slash
     */
    public static function getUrl(): string
    {
        $url = trim(Env::getFilled(EnvConstants::CHAT_BOT_URL, ''));
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
        $timeout = Env::getFloat(EnvConstants::CHAT_BOT_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC);

        return $timeout > 0 ? $timeout : LLMConstants::DEFAULT_TIMEOUT_SEC;
    }

    /**
     * Whether to use external LLM provider (e.g. OpenAI) instead of local Ollama.
     *
     * @return bool True if external provider should be used
     */
    public static function useExternalProvider(): bool
    {
        $value = Env::getFilled(EnvConstants::CHAT_BOT_PROVIDER, 'local');

        return strtolower($value) === 'external';
    }

    /**
     * Get chat response language (ISO 639-1 code).
     *
     * @return string Language code (e.g. ru, en). Default: ru.
     */
    public static function getLanguage(): string
    {
        $value = Env::getFilled(EnvConstants::CHAT_BOT_LANGUAGE, 'ru');

        return strtolower(trim($value)) ?: 'ru';
    }

    /**
     * Get language instruction for LLM prompt (English instruction text).
     *
     * @return string Instruction text (e.g. "Respond in Russian.", "Respond in English.")
     */
    public static function getLanguageInstruction(): string
    {
        return match (self::getLanguage()) {
            'ru' => 'Respond in Russian.',
            'en' => 'Respond in English.',
            'de' => 'Respond in German.',
            'fr' => 'Respond in French.',
            'es' => 'Respond in Spanish.',
            default => 'Match the language of recent messages.',
        };
    }
}
