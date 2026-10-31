<?php

declare(strict_types=1);

namespace Demo\Polls\Database\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;

/** Framework page head adoption for the polls demo. */
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
            HilosPageConstants::HILOS_ANALYTICS_PERSON => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_ANALYTICS_PERSON],
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
        ];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No additional sections */
    public static function dashboardSections(): array
    {
        return [];
    }
}
