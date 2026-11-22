<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Backup;

use Demo\EcommerceShop\Database\Object\Collection\Categories;
use Demo\EcommerceShop\Database\Object\Collection\CategorySlugs;
use Demo\EcommerceShop\Database\Object\Collection\Products;
use Demo\EcommerceShop\Database\Object\Collection\ProductSlugs;
use Demo\EcommerceShop\Database\Object\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Database\Object\Collection\Vouchers;
use Demo\EcommerceShop\Database\Object\Collection\Warehouses;
use Hilos\Backup\BackupConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Collection\Files;

/**
 * BackupCatalog - this demo's backup catalog.
 *
 * Activates the framework backup subsystem via Hilos::BACKUP_CATALOG. It is the
 * project-owned container for the per-connection reference-object registry under
 * {@see BackupConstants::CATALOG_REFERENCES}. The demo declares no schedule, so it takes
 * the framework default (one daily full backup at 03:00 on the agent mechanism); a project
 * overrides it by adding {@see BackupConstants::CATALOG_SCHEDULE} entries here.
 *
 * The reference registry lists the reference/seed collections per connection index. The
 * shop's catalog tables - warehouses, categories, products, their addresses and stock - are
 * its own, and Migration/Seed/001_hilos_flowers.sql seeds them; categories and products name
 * their pictures by the number of a files registry row, so the registry rides with them. On
 * connection index 0 these tables are the reference ones schema-seed keeps rows of; the carts
 * of the visitors are not, the seed writes none. The voucher table, seeded by
 * Migration/Seed/002_hilos_vouchers.sql, is a reference table of connection index 0, and
 * schema-seed keeps every row of it. The bytes of the pictures are not in the
 * archive: a backup carries the database only (files-registry.md, "What Is Not Here").
 *
 * What a restore into a lesser environment must rewrite is declared on each table's own
 * Entity ({@see Entity::META_PII} and {@see Entity::META_PII_NOT_PERSONAL}).
 */
final class BackupCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Backup catalog (reference registry)
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_REFERENCES => [
                0 => [
                    Warehouses::class,
                    Categories::class,
                    CategorySlugs::class,
                    Products::class,
                    ProductSlugs::class,
                    ProductWarehouseStocks::class,
                    Vouchers::class,
                    Files::class,
                ],
            ],
        ];
    }
}
