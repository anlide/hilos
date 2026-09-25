<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/**
 * Framework settings-catalog fragment for operation-level step-up (HIL-495).
 */
final class StepUpSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            StepUpSettings::DISABLED_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => StepUpDisabledRule::class,
            ],
        ];
    }
}
