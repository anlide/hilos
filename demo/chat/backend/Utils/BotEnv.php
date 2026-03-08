<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Utils\Env;

/**
 * BotEnv - Reads bot LLM config from env via Env
 *
 * Used by BotAgent for async message generation.
 */
class BotEnv
{
    public static function getModel(): string
    {
        return Env::getFilled(EnvConstants::CHAT_BOT_MODEL, ChatLLMConstants::MODEL_BOT);
    }

    public static function getUrl(): string
    {
        $url = Env::getFilled(EnvConstants::CHAT_BOT_URL, LLMConstants::DEFAULT_LOCAL_URL);
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/api/generate')) {
            $url = substr($url, 0, -strlen('/api/generate'));
        }

        return $url;
    }

    public static function getTimeoutSec(): float
    {
        $timeout = Env::getFloat(EnvConstants::CHAT_BOT_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC);

        return $timeout > 0 ? $timeout : LLMConstants::DEFAULT_TIMEOUT_SEC;
    }

    public static function useExternalProvider(): bool
    {
        $value = Env::getFilled(EnvConstants::CHAT_BOT_PROVIDER, 'local');

        return strtolower($value) === 'external';
    }

    /**
     * Get chat response language (ISO 639-1 code).
     * Default: ru.
     */
    public static function getLanguage(): string
    {
        $value = Env::getFilled(EnvConstants::CHAT_BOT_LANGUAGE, 'ru');

        return strtolower(trim($value)) ?: 'ru';
    }

    /**
     * Get language instruction for LLM prompt (English instruction text).
     * Example: "Respond in Russian." or "Respond in English."
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
