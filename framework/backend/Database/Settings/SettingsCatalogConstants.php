<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

/**
 * SettingsCatalogConstants - Constants for settings catalog structure.
 *
 * All magic words for catalog entries and types are defined here.
 */
final class SettingsCatalogConstants
{
    // Catalog entry keys (structure of each catalog entry)
    public const string CATALOG_ENTRY_TYPE = 'type';
    public const string CATALOG_ENTRY_DEFAULT_VALUE = 'default_value';
    public const string CATALOG_ENTRY_RULE = 'rule';
    public const string CATALOG_ENTRY_ADMIN_VIEW_VISIBLE = 'admin_view_visible';
    public const string CATALOG_DEFAULT_SETTING_KEY = 'setting_key';

    // Setting value types
    public const string TYPE_STRING = 'string';
    public const string TYPE_INTEGER = 'integer';
    public const string TYPE_FLOAT = 'float';
    public const string TYPE_BOOLEAN = 'boolean';

    // Stub setting keys (example keys for SettingsCatalogStub)
    public const string STUB_KEY_EXAMPLE_STRING = 'example_string';
    public const string STUB_KEY_EXAMPLE_INTEGER = 'example_integer';
    public const string STUB_KEY_EXAMPLE_BOOLEAN = 'example_boolean';

    /** Chat bot timeout setting key; same name as the demo seed — type float */
    public const string STUB_KEY_CHAT_BOT_TIMEOUT_SEC = 'chat_bot_timeout_sec';
}
