<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Database\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;

/** User, Logs, Change Log, Analytics Person, Communications and Appearance head adoption for the binance-btc-tracker demo. */
final class PageCatalog implements PageCatalogProviderInterface
{
    /**
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Complete framework entries with head adoption enabled
     */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_USERS => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_USERS],
                [
                    PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Users',
                    PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
                ],
            ),
            HilosPageConstants::HILOS_USER => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_USER],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_LOGS => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_LOGS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_CHANGE_LOG => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG_TABLE],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_ANALYTICS_PERSON => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_PERSON],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_COMMUNICATIONS => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_APPEARANCE => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_APPEARANCE],
                [
                    PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Appearance',
                    PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
                ],
            ),
            HilosPageConstants::HILOS_BACKUP => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_BACKUP],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
        ];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No additional dashboard sections */
    public static function dashboardSections(): array
    {
        return [];
    }
}
