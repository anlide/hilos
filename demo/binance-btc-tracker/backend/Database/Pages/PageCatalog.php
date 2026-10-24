<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Database\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;

/** Enables the shared user head for the demo's Vue frontend. */
final class PageCatalog implements PageCatalogProviderInterface
{
    /**
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> Framework metadata with the user head enabled
     */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_USER => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_USER],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
        ];
    }

    /** @return list<array{title: string, description: string, items: list<string>}> No project dashboard groups */
    public static function dashboardSections(): array
    {
        return [];
    }
}
