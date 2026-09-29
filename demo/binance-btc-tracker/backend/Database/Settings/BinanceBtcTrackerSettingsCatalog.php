<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Database\Settings;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\Definition\LogsFeature;
use Hilos\Log\LogSettingsCatalog;

/**
 * BinanceBtcTrackerSettingsCatalog - Project settings catalog for the binance-btc-tracker demo.
 *
 * Declares the allowed setting keys, their types, and default values; the framework reads it back
 * through Hilos::$setting->catalog(). Keys present in the DB but absent here are treated as
 * orphans.
 *
 * The catalog is deliberately narrow: it carries exactly what an activated feature requires and
 * nothing else. The logs section needs its own fragment - the rotation thresholds and the logging
 * modes its screens write - and refuses to start without it; the framework example keys and the
 * sign-in fragments arrive with the leaf that moves the settings e2e onto this demo (HIL-1219).
 *
 * @see LogsFeature The feature whose required fragment this catalog carries
 * @see LogSettingsCatalog Keys of the logs feature this demo activates
 */
final class BinanceBtcTrackerSettingsCatalog implements CatalogProviderInterface
{
    /**
     * Returns the settings catalog for the binance-btc-tracker demo.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getCatalog(): array
    {
        return LogSettingsCatalog::getCatalog();
    }
}
