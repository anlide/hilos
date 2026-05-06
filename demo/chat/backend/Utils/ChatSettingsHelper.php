<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Demo\Chat\Constants\ChatAttachmentDefaults;
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
     *
     * @return string Model name
     */
    public static function getBotModel(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_BOT_MODEL) ?: ChatLLMConstants::MODEL_BOT;
    }

    /**
     * Bot LLM API URL (empty = use LLM_LOCAL_URL).
     *
     * @return string API URL
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
     *
     * @return bool True if external
     */
    public static function getBotProviderIsExternal(): bool
    {
        return strtolower(trim(self::getBotProvider())) === 'external';
    }

    /**
     * Bot request timeout in seconds.
     *
     * @return float Timeout in seconds
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
     *
     * @return string Language code
     */
    public static function getBotLanguage(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_BOT_LANGUAGE) ?: 'ru';
    }

    /**
     * Moderation LLM model name.
     *
     * @return string Model name
     */
    public static function getModerationModel(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_MODERATION_MODEL) ?: ChatLLMConstants::MODEL_MODERATION;
    }

    /**
     * Moderation API URL (empty = use LLM_LOCAL_URL).
     *
     * @return string API URL
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
     *
     * @return string Provider name
     */
    public static function getModerationProvider(): string
    {
        return self::getString(ChatSettingsConstants::CHAT_MODERATION_PROVIDER) ?: 'local';
    }

    /**
     * Whether moderation uses external LLM provider.
     *
     * @return bool True if external
     */
    public static function isModerationProviderExternal(): bool
    {
        return strtolower(trim(self::getModerationProvider())) === 'external';
    }

    /**
     * Alias for isModerationProviderExternal (for ModeratorAgent).
     *
     * @return bool True if external
     */
    public static function getModerationProviderIsExternal(): bool
    {
        return self::isModerationProviderExternal();
    }

    /**
     * Moderation request timeout in seconds.
     *
     * @return float Timeout in seconds
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
     * Max size in bytes for a single chat attachment.
     */
    public static function getAttachmentMaxFileBytes(): int
    {
        $v = self::getInt(ChatSettingsConstants::CHAT_ATTACHMENT_MAX_FILE_BYTES, ChatAttachmentDefaults::DEFAULT_MAX_FILE_BYTES);

        return $v > 0 ? $v : ChatAttachmentDefaults::DEFAULT_MAX_FILE_BYTES;
    }

    /**
     * Max combined size in bytes of published attachments (soft global cap).
     */
    public static function getAttachmentMaxTotalBytes(): int
    {
        $v = self::getInt(ChatSettingsConstants::CHAT_ATTACHMENT_MAX_TOTAL_BYTES, ChatAttachmentDefaults::DEFAULT_MAX_TOTAL_BYTES);

        return $v > 0 ? $v : ChatAttachmentDefaults::DEFAULT_MAX_TOTAL_BYTES;
    }

    /**
     * Get string value from settings. Returns empty string when not found.
     *
     * @param string $key Setting key
     * @return string Value or empty string
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

    private static function getInt(string $key, int $default): int
    {
        $setting = Hilos::$db->settings->findByKey($key);
        if ($setting === null || $setting->value === null || $setting->value === '') {
            return $default;
        }
        if (is_numeric($setting->value)) {
            return (int) $setting->value;
        }

        return $default;
    }
}
