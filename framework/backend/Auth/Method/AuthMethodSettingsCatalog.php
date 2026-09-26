<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * AuthMethodSettingsCatalog - the framework settings-catalog fragment for the sign-in method set (HIL-427).
 *
 * Two keys. The list of switched-off methods ({@see AuthMethodSettings::DISABLED_KEY}),
 * empty by default so every wired method is on; the key names its rule, so every write
 * path refuses an unknown method and a list that switches every method off. And whether a
 * passkey may start an account on an unconfirmed address ({@see PasskeyAddressPolicy},
 * HIL-1105), off by default; a yes or no has no value to refuse, so it names no rule. A
 * project folds this into its own catalog with `array_replace(parent::getCatalog(), ...)`.
 */
final class AuthMethodSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Builds the sign-in method settings entries.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            AuthMethodSettings::DISABLED_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => AuthMethodsDisabledRule::class,
            ],
            PasskeyAddressPolicy::SETTING_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => false,
            ],
        ];
    }
}
