<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Pages;

use Demo\Chat\Constants\PageConstants;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogStub;

/**
 * ChatPageCatalog - Page catalog for chat demo.
 *
 * Defines the page tree used by frontend breadcrumb rendering.
 */
final class ChatPageCatalog extends PageCatalogStub
{
    /**
     * Returns page catalog for this project.
     *
     * @return array<string, array{
     *     parent_id: ?string,
     *     label: string,
     *     dynamic_param?: string,
     *     hide_breadcrumb?: bool,
     * }>
     */
    public static function getCatalog(): array
    {
        return array_merge(parent::getCatalog(), [
            PageConstants::MAIN => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => null,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Home',
                PageCatalogConstants::CATALOG_ENTRY_HIDE_BREADCRUMB => true,
            ],
            PageConstants::PROFILE => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::MAIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Profile',
                PageCatalogConstants::CATALOG_ENTRY_HIDE_BREADCRUMB => true,
            ],
            PageConstants::ADMIN => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => null,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Admin',
            ],
            PageConstants::ADMIN_USERS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Users',
            ],
            PageConstants::USER => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::MAIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User',
                PageCatalogConstants::CATALOG_ENTRY_DYNAMIC_PARAM => 'id',
            ],
            PageConstants::ADMIN_MODERATOR => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Moderator',
            ],
            PageConstants::ADMIN_BOTS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Bots',
            ],
            PageConstants::BOT => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::MAIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Bot',
                PageCatalogConstants::CATALOG_ENTRY_DYNAMIC_PARAM => 'id',
            ],
            PageConstants::HILOS_DASHBOARD => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Hilos',
            ],
            PageConstants::HILOS_SETTINGS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Settings',
            ],
            PageConstants::HILOS_I18N => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Internationalization',
            ],
            PageConstants::HILOS_GUARDIAN => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Guardian',
            ],
            PageConstants::HILOS_GUARDIAN_AGENT => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_GUARDIAN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Guardian Agent',
                PageCatalogConstants::CATALOG_ENTRY_DYNAMIC_PARAM => 'agentId',
            ],
            PageConstants::HILOS_ANALYTICS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Analytics',
            ],
        ]);
    }
}
