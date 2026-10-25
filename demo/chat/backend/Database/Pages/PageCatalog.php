<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Pages;

use Demo\Chat\Constants\PageConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;

/**
 * PageCatalog - Chat admin pages, dashboard group, and User and Communications head adoption.
 *
 * One screen the framework does not know about: the library bots. It hangs off the
 * dashboard, so it reaches the panel as a card of its own group rather than as a
 * link buried in someone else's section.
 *
 * The captions live here rather than on the frontend for the reason the whole catalog does: a
 * heading is text in the visitor's language, and only the backend knows the language.
 *
 * @see PageCatalogProviderInterface
 */
final class PageCatalog implements PageCatalogProviderInterface
{
    /**
     * @return array<string, array{
     *     label: string, lead: string, parent?: string, icon?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> The chat demo's admin pages and adopted framework owners
     */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_USER => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_USER],
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
            PageConstants::ADMIN_BOTS => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Bots',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Library bots: profiles, status, and behavior.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_ICON => 'bi-robot',
            ],
        ];
    }

    /**
     * @return list<array{title: string, description: string, items: list<string>}> The chat demo's dashboard group
     */
    public static function dashboardSections(): array
    {
        return [
            [
                PageCatalogConstants::SECTION_TITLE => 'Chat administration',
                PageCatalogConstants::SECTION_DESCRIPTION => 'Application-specific admin areas for the chat demo.',
                PageCatalogConstants::SECTION_ITEMS => [
                    PageConstants::ADMIN_BOTS,
                ],
            ],
        ];
    }
}
