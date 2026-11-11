<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Database\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;

/** Framework page head adoption for the concert-tickets demo. */
final class PageCatalog implements PageCatalogProviderInterface
{
    /**
     * @return array<string, array{
     *     label: string, lead: string, parent?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Complete framework entries with head adoption enabled
     */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_ANALYTICS_PERSON => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_PERSON],
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
            HilosPageConstants::HILOS_LOGS => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_LOGS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
        ];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No additional sections */
    public static function dashboardSections(): array
    {
        return [];
    }
}
