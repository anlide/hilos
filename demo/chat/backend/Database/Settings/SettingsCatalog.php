<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Settings;

use Demo\Chat\Constants\ChatAttachmentDefaults;
use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * SettingsCatalog - Project settings catalog.
 *
 * Defines allowed setting keys, types, and default values.
 * Keys not in this catalog that exist in DB are considered orphans.
 *
 * @see SettingsCatalogConstants
 * @see ChatSettingsConstants Chat-specific keys for Bot and Moderator agents
 */
final class SettingsCatalog
{
    /**
     * Returns settings catalog for this project.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getCatalog(): array
    {
        return [
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
            ],
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
            ],
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => false,
            ],
            // Shared LLM defaults
            ChatSettingsConstants::DEFAULT_BOT_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ChatLLMConstants::MODEL_BOT,
            ],
            ChatSettingsConstants::DEFAULT_BOT_URL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
            ],
            ChatSettingsConstants::DEFAULT_BOT_PROVIDER => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'local',
            ],
            ChatSettingsConstants::DEFAULT_BOT_TIMEOUT_SEC => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 90.0,
            ],
            // Chat Bot LLM settings
            ChatSettingsConstants::CHAT_BOT_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_MODEL,
                ],
            ],
            ChatSettingsConstants::CHAT_BOT_URL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_URL,
                ],
            ],
            ChatSettingsConstants::CHAT_BOT_PROVIDER => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_PROVIDER,
                ],
            ],
            ChatSettingsConstants::CHAT_BOT_TIMEOUT_SEC => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_TIMEOUT_SEC,
                ],
            ],
            ChatSettingsConstants::CHAT_BOT_LANGUAGE => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'ru',
            ],
            // Chat Moderation LLM settings
            ChatSettingsConstants::CHAT_MODERATION_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_MODEL,
                ],
            ],
            ChatSettingsConstants::CHAT_MODERATION_URL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_URL,
                ],
            ],
            ChatSettingsConstants::CHAT_MODERATION_PROVIDER => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_PROVIDER,
                ],
            ],
            ChatSettingsConstants::CHAT_MODERATION_TIMEOUT_SEC => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => [
                    SettingsCatalogConstants::CATALOG_DEFAULT_SETTING_KEY => ChatSettingsConstants::DEFAULT_BOT_TIMEOUT_SEC,
                ],
            ],
            ChatSettingsConstants::CHAT_ATTACHMENT_MAX_FILE_BYTES => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ChatAttachmentDefaults::DEFAULT_MAX_FILE_BYTES,
            ],
            ChatSettingsConstants::CHAT_ATTACHMENT_MAX_TOTAL_BYTES => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => ChatAttachmentDefaults::DEFAULT_MAX_TOTAL_BYTES,
            ],
        ];
    }
}
