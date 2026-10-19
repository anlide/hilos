<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;

/** Framework settings fragment for the shared upload byte budget. */
final class UploadsSettingsCatalog implements CatalogProviderInterface
{
    /** Setting key: bytes the master may reserve for incoming upload data. */
    public const string MEMORY_POOL_BYTES_KEY = 'uploads.memory_pool_bytes';

    /** Default upload budget in bytes. */
    public const int DEFAULT_MEMORY_POOL_BYTES = 2_147_483_648;

    /**
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::MEMORY_POOL_BYTES_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_MEMORY_POOL_BYTES,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => PositiveIntegerRule::class,
            ],
        ];
    }
}
