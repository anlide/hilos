<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Settings;

/**
 * ChatSettingsConstants - Setting keys for chat demo (Bot, Moderator LLM config).
 *
 * Used by SettingsCatalog and ChatSettingsHelper.
 * Keys not in catalog (e.g. orphan_test) are for testing orphan functionality.
 */
final class ChatSettingsConstants
{
    // Bot agent
    public const string CHAT_BOT_MODEL = 'chat_bot_model';
    public const string CHAT_BOT_URL = 'chat_bot_url';
    public const string CHAT_BOT_PROVIDER = 'chat_bot_provider';
    public const string CHAT_BOT_TIMEOUT_SEC = 'chat_bot_timeout_sec';
    public const string CHAT_BOT_LANGUAGE = 'chat_bot_language';

    // Moderator agent
    public const string CHAT_MODERATION_MODEL = 'chat_moderation_model';
    public const string CHAT_MODERATION_URL = 'chat_moderation_url';
    public const string CHAT_MODERATION_PROVIDER = 'chat_moderation_provider';
    public const string CHAT_MODERATION_TIMEOUT_SEC = 'chat_moderation_timeout_sec';
    public const string CHAT_MODERATION_USERS = 'chat_moderation_users';
    public const string CHAT_MODERATION_BOTS = 'chat_moderation_bots';

    /** Key for orphan test — NOT in catalog. Used in seed to test orphan detection. */
    public const string ORPHAN_TEST_KEY = 'orphan_test';
}
