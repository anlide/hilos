<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Utils\Env;

/**
 * ModerationEnv - Reads moderation LLM config from env via Env
 *
 * Extends Env usage for moderation-specific variables.
 */
class ModerationEnv
{
    public static function getModel(): string
    {
        return Env::getFilled(
            EnvConstants::CHAT_MODERATION_MODEL,
            Env::getFilled(EnvConstants::LLM_LOCAL_CHAT_MODEL, ChatLLMConstants::MODEL_MODERATION)
        );
    }

    public static function getUrl(): string
    {
        $url = trim(Env::getFilled(EnvConstants::CHAT_MODERATION_URL, ''));
        if ($url === '') {
            $url = Env::getFilled(EnvConstants::LLM_LOCAL_URL, LLMConstants::DEFAULT_LOCAL_URL);
        }
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/api/generate')) {
            $url = substr($url, 0, -strlen('/api/generate'));
        }

        return $url;
    }

    public static function getTimeoutSec(): float
    {
        $timeout = Env::getFloat(EnvConstants::CHAT_MODERATION_TIMEOUT_SEC, LLMConstants::DEFAULT_TIMEOUT_SEC);

        return $timeout > 0 ? $timeout : LLMConstants::DEFAULT_TIMEOUT_SEC;
    }

    public static function useExternalProvider(): bool
    {
        $value = Env::getFilled(EnvConstants::CHAT_MODERATION_PROVIDER, 'local');

        return strtolower($value) === 'external';
    }

    /**
     * Whether moderation is enabled for user messages.
     * Default: true.
     */
    public static function moderateUsersEnabled(): bool
    {
        return self::parseBool(EnvConstants::CHAT_MODERATION_USERS, true);
    }

    /**
     * Whether moderation is enabled for bot messages.
     * Default: false.
     */
    public static function moderateBotsEnabled(): bool
    {
        return self::parseBool(EnvConstants::CHAT_MODERATION_BOTS, false);
    }

    private static function parseBool(EnvConstants $name, bool $default): bool
    {
        $value = Env::getFilled($name, $default ? 'true' : 'false');
        $v = strtolower(trim($value));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}
