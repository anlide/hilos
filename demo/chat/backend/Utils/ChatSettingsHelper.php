<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Demo\Chat\Constants\ChatLLMConstants;
use Demo\Chat\Database\Settings\ChatSettingsConstants;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Utils\Env;

/**
 * ChatSettingsHelper - Reads chat-related settings from hilos_setting table.
 *
 * All values come from DB (seed 003). When setting is missing, uses code defaults.
 * LLM_LOCAL_URL is used for empty URL (base Ollama endpoint, not in settings).
 */
final class ChatSettingsHelper
{
    /**
     * Bot LLM model name.
     */
    public static function getBotModel(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_BOT_MODEL) ?: ChatLLMConstants::MODEL_BOT;
    }

    /**
     * Bot LLM API URL (empty = use LLM_LOCAL_URL).
     */
    public static function getBotUrl(): string
    {
        $val = self::getString(ChatSettingsConstants::CHAT_BOT_URL);
        if ($val !== '') {
            return $val;
        }
        return Env::getFilled(EnvConstants::LLM_LOCAL_URL, LLMConstants::DEFAULT_LOCAL_URL);
    }

    /**
     * Bot provider: local | external.
     */
    public static function getBotProvider(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_BOT_PROVIDER) ?: 'local';
    }

    /**
     * Whether bot uses external LLM provider.
     */
    public static function getBotProviderIsExternal(): bool
    {
        return strtolower(trim(self::getBotProvider())) === 'external';
    }

    /**
     * Bot request timeout in seconds.
     */
    public static function getBotTimeoutSec(): float
    {
        $val = self::getString(ChatSettingsConstants::CHAT_BOT_TIMEOUT_SEC);
        if ($val !== '' && is_numeric($val)) {
            return (float) $val;
        }
        return LLMConstants::DEFAULT_TIMEOUT_SEC;
    }

    /**
     * Bot output language (ISO 639-1: ru, en).
     */
    public static function getBotLanguage(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_BOT_LANGUAGE) ?: 'ru';
    }

    /**
     * Moderation LLM model name.
     */
    public static function getModerationModel(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_MODERATION_MODEL) ?: ChatLLMConstants::MODEL_MODERATION;
    }

    /**
     * Moderation API URL (empty = use LLM_LOCAL_URL).
     */
    public static function getModerationUrl(): string
    {
        $val = self::getString(ChatSettingsConstants::CHAT_MODERATION_URL);
        if ($val !== '') {
            return $val;
        }
        return Env::getFilled(EnvConstants::LLM_LOCAL_URL, LLMConstants::DEFAULT_LOCAL_URL);
    }

    /**
     * Moderation provider: local | external.
     */
    public static function getModerationProvider(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_MODERATION_PROVIDER) ?: 'local';
    }

    /**
     * Whether moderation uses external LLM provider.
     */
    public static function isModerationProviderExternal(): bool
    {
        return strtolower(trim(self::getModerationProvider())) === 'external';
    }

    /**
     * Alias for isModerationProviderExternal (for ModeratorAgent).
     */
    public static function getModerationProviderIsExternal(): bool
    {
        return self::isModerationProviderExternal();
    }

    /**
     * Moderation request timeout in seconds.
     */
    public static function getModerationTimeoutSec(): float
    {
        $val = self::getString(ChatSettingsConstants::CHAT_MODERATION_TIMEOUT_SEC);
        if ($val !== '' && is_numeric($val)) {
            return (float) $val;
        }
        return LLMConstants::DEFAULT_TIMEOUT_SEC;
    }

    /**
     * Enable moderation for user messages.
     */
    public static function getModerationUsers(): bool
    {
        $val = self::getString(ChatSettingsConstants::CHAT_MODERATION_USERS);
        if ($val !== '') {
            return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }
        return true;
    }

    /**
     * Enable moderation for bot messages.
     */
    public static function getModerationBots(): bool
    {
        $val = self::getString(ChatSettingsConstants::CHAT_MODERATION_BOTS);
        if ($val !== '') {
            return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }
        return false;
    }

    /**
     * Get string value from settings. Returns empty string when not found.
     */
    private static function getString(string $key): string
    {
        $setting = Hilos::$db->settings->findByKey($key);
        if ($setting === null) {
            return '';
        }
        $value = $setting->value;
        return $value !== null ? (string) $value : '';
    }
}
