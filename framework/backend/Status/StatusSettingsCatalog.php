<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;

/** Settings fragment for the publication deadline of newly detected Status facts. */
final class StatusSettingsCatalog implements CatalogProviderInterface
{
    public const string PUBLISH_DELAY_SECONDS = 'status.publish_delay_seconds';
    public const int DEFAULT_PUBLISH_DELAY_SECONDS = 300;

    /** @return array<string, array<string, mixed>> Status publication setting */
    public static function getCatalog(): array
    {
        return [self::PUBLISH_DELAY_SECONDS => [
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_PUBLISH_DELAY_SECONDS,
            SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
        ]];
    }
}
