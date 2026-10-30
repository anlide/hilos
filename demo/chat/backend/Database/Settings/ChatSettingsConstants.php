<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Settings;

/**
 * ChatSettingsConstants - Setting keys for chat demo.
 *
 * Used by SettingsCatalog and setting accessors.
 * Keys not in catalog (e.g. orphan_test) are for testing orphan functionality.
 */
final class ChatSettingsConstants
{
    // Shared bot defaults
    public const string DEFAULT_BOT_MODEL = 'default_bot_model';
    public const string DEFAULT_BOT_URL = 'default_bot_url';
    public const string DEFAULT_BOT_PROVIDER = 'default_bot_provider';
    public const string DEFAULT_BOT_TIMEOUT_SEC = 'default_bot_timeout_sec';

    // Bot agent
    public const string CHAT_BOT_MODEL = 'chat_bot_model';
    public const string CHAT_BOT_URL = 'chat_bot_url';
    public const string CHAT_BOT_PROVIDER = 'chat_bot_provider';
    public const string CHAT_BOT_TIMEOUT_SEC = 'chat_bot_timeout_sec';
    public const string CHAT_BOT_LANGUAGE = 'chat_bot_language';

    /** Max single attachment size in bytes; the chat's upload target reads it on every declaration */
    public const string CHAT_ATTACHMENT_MAX_FILE_BYTES = 'chat_attachment_max_file_bytes';

    /** Key for orphan test — NOT in catalog. Used in seed to test orphan detection. */
    public const string ORPHAN_TEST_KEY = 'orphan_test';
}
