<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\SettingsCatalogConstants;

/** The node journal ceiling uses one cluster setting, no smaller than one ready file. */
final class AnalyticsSettingsCatalog implements CatalogProviderInterface
{
    public const string JOURNAL_MAX_BYTES = 'analytics.journal.max_bytes';
    public const int DEFAULT_JOURNAL_MAX_BYTES = 1073741824;

    /**
     * @return array<string, array<string, mixed>> Settings keyed by their catalog keys
     */
    public static function getCatalog(): array
    {
        return [
            self::JOURNAL_MAX_BYTES => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::DEFAULT_JOURNAL_MAX_BYTES,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => AnalyticsJournalCeilingRule::class,
            ],
        ];
    }
}
