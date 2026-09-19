<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * OAuthSettingsCatalog - the framework settings-catalog fragment for OAuth sign-in (HIL-286).
 *
 * One key, the shared return address every provider redirects back to. It is a setting
 * and not a provider field because it is one for the whole application. Its default is
 * empty - the real default lives in env, and {@see OAuthConfigResolver} reads the setting
 * only when an admin override is persisted. A project folds this into its own catalog
 * with `array_replace(parent::getCatalog(), ...)`.
 *
 * Nothing else about a provider is a setting: the settings catalog knows no secret
 * field, and the general settings table shows every key it holds, so the provider's
 * own data lives in hilos_oauth_provider.
 */
final class OAuthSettingsCatalog implements CatalogProviderInterface
{
    /** Setting key of the shared return address. */
    public const string REDIRECT_URI_KEY = 'oauth_redirect_uri';

    /**
     * Builds the OAuth settings entries.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::REDIRECT_URI_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
            ],
        ];
    }
}
