<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;

/** Framework settings fragment for the WebSocket delivery window. */
final class DeliverySettingsCatalog implements CatalogProviderInterface
{
    /** Setting key: duration of a delivery window in milliseconds. */
    public const string WINDOW_MS_KEY = 'websocket.delivery_window_ms';

    /** Default delivery window in milliseconds. */
    public const int DEFAULT_WINDOW_MS = 1000;

    /**
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    public static function getCatalog(): array
    {
        return [
            self::WINDOW_MS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_WINDOW_MS,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => PositiveIntegerRule::class,
            ],
        ];
    }
}
