<?php

declare(strict_types=1);

namespace Hilos\Database\Pages;

/**
 * PageCatalogStub - Stub-example of page catalog.
 *
 * Project code can extend this class and merge its own page tree.
 *
 * @see PageCatalogConstants
 */
class PageCatalogStub
{
    /**
     * Returns stub catalog array for example reference.
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
        return [
            PageCatalogConstants::STUB_KEY_MAIN => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => null,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Stub Main',
            ],
            PageCatalogConstants::STUB_KEY_CHILD => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageCatalogConstants::STUB_KEY_MAIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Stub Child',
            ],
        ];
    }
}
