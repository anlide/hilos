<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Pages;

use Demo\EcommerceShop\Constants\PageConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;

/**
 * PageCatalog - the shop's own admin pages, its dashboard group, and adopted framework heads.
 *
 * Besides the framework owners whose shared head the demo adopts, the catalog carries the
 * screens the framework does not know about — the shop's administration: the warehouses, the
 * categories and the products. They hang off the dashboard, so they reach the panel as cards of their own group,
 * and one product's page hangs off the products as an entity of its own.
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
     *     label: string, lead: string, parent?: string,
     *     breadcrumb?: string, entity?: bool, pageHead?: bool
     * }> The shop's admin pages and the framework entries whose head the demo adopts
     */
    public static function pages(): array
    {
        return [
            HilosPageConstants::HILOS_COMMUNICATIONS => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL],
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
            HilosPageConstants::HILOS_CHANGE_LOG => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_CHANGE_LOG_TABLE],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            HilosPageConstants::HILOS_BACKUP => array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_BACKUP],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            PageConstants::ADMIN_WAREHOUSES => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Warehouses',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Where the stock is kept.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Warehouses',
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
            ],
            PageConstants::ADMIN_CATEGORIES => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Categories',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'How the catalog is grouped on the shop front.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Categories',
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
            ],
            PageConstants::ADMIN_PRODUCTS => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Products',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Products, prices, and stock by warehouse.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Products',
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
            ],
            PageConstants::ADMIN_PRODUCT => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Product',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'One product of the catalog: its kind, picture, category, price and stock.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => PageConstants::ADMIN_PRODUCTS,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Product',
                PageCatalogConstants::CATALOG_ENTRY_ENTITY => true,
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
            ],
            PageConstants::ADMIN_PRODUCT_STOCK => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Stock',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Units of this product by warehouse.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => PageConstants::ADMIN_PRODUCT,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Stock',
            ],
            PageConstants::ADMIN_VOUCHERS => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Vouchers',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Discount codes buyers enter in the cart.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Vouchers',
                PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
            ],
        ];
    }

    /**
     * @return list<array{title: string, description: string, items: list<string>}> The shop's administration group
     */
    public static function dashboardSections(): array
    {
        return [
            [
                PageCatalogConstants::SECTION_TITLE => 'Shop administration',
                PageCatalogConstants::SECTION_DESCRIPTION => 'Warehouses, catalog, and vouchers.',
                PageCatalogConstants::SECTION_ITEMS => [
                    PageConstants::ADMIN_WAREHOUSES,
                    PageConstants::ADMIN_CATEGORIES,
                    PageConstants::ADMIN_PRODUCTS,
                    PageConstants::ADMIN_VOUCHERS,
                ],
            ],
        ];
    }
}
