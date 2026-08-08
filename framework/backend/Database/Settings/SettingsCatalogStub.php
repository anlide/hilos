<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * SettingsCatalogStub - Stub-example of settings catalog.
 *
 * Project copies or extends this to define its own catalog.
 * Use SettingsCatalogConstants for all keys and types.
 *
 * @see SettingsCatalogConstants
 */
final class SettingsCatalogStub implements CatalogProviderInterface
{
    /**
     * Two budgets that merely start out equal, deliberately kept as two constants:
     * they answer to different settings and are free to diverge. One shared constant
     * would claim a link between them that does not exist — see
     * docs/agents/code-style/magic-values.md, "the same value is not one quantity".
     *
     * @var float Default reply budget of the chat bot, in seconds
     */
    private const float DEFAULT_CHAT_BOT_TIMEOUT_SEC = 90.0;

    /** @var float Default verdict budget of chat moderation, in seconds */
    private const float DEFAULT_CHAT_MODERATION_TIMEOUT_SEC = 90.0;

    /**
     * Returns stub catalog array for example reference.
     *
     * @return array<string, array{type: string, default_value: mixed}>
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
            SettingsCatalogConstants::STUB_KEY_CHAT_BOT_TIMEOUT_SEC => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_CHAT_BOT_TIMEOUT_SEC,
            ],
            SettingsCatalogConstants::STUB_KEY_CHAT_MODERATION_TIMEOUT_SEC => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_FLOAT,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_CHAT_MODERATION_TIMEOUT_SEC,
            ],
        ];
    }
}
