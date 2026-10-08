<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;

/**
 * Settings fragment to fold into a project's catalog wherever the auth throttle is on (HIL-1280).
 * Zero is a valid grace: the first verdict that does not arrive in time already refuses.
 */
final class AuthThrottleSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Settings keyed by their catalog keys
     */
    public static function getCatalog(): array
    {
        return [
            AuthThrottleSettings::OUTAGE_GRACE_SECONDS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => AuthThrottleSettings::DEFAULT_OUTAGE_GRACE_SECONDS,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
            ],
        ];
    }
}
