<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\StockLibraryAgent;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Files\FileVisibility;
use Hilos\Testing\RollbackIntegrationTestCase;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Mounts the shop before the managed rollback scope and starts the stock library the way its worker does.
 *
 * It also writes the rows the stock stands on — warehouses and products with their categories and
 * pictures — straight into their tables past their libraries, with no file on disk.
 */
abstract class StockLibraryEnvironment extends RollbackIntegrationTestCase
{
    protected const string WAREHOUSE_ADDRESS = 'Volodymyrska St, 1';

    private ?StockLibraryAgent $library = null;
    private ?int $executionToken = null;

    /**
     * Uses the migrated test database without taking over an already mounted project context.
     *
     * The products of a case go in past the ORM, so the loaded memory is forgotten first: a
     * products library started earlier in this process declared the products whole, and a
     * product written after that would be read as absent. Forgotten, they are read again inside
     * the case, and the rollback forgets that read with the rows.
     * Then the library claims what it owns and runs its start under its own frame, so the stock
     * is declared complete before the first case writes a row.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::$db->forgetLoadedDataForTest();
        $this->library = new StockLibraryAgent();
        OwnershipDeclaration::claimAll($this->library);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->library->getId()));
        $this->library->onStart();
    }

    /** Releases the library's claim and restores the caller after the harness rolls back. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->library !== null) {
            TruthSourceRegistry::unregisterAgent($this->library->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
            $this->library = null;
        }
        if ($this->executionToken !== null) {
            ExecutionContext::pop($this->executionToken);
            $this->executionToken = null;
        }
    }

    /**
     * Writes a warehouse row past the warehouses library.
     *
     * @param ?string $name Name of the warehouse, or null for a name of its own
     * @return int Id of the new warehouse
     */
    protected static function warehouse(?string $name = null): int
    {
        Database::sql(
            'INSERT INTO warehouse (name, address) VALUES (?, ?)',
            [$name ?? 'Warehouse ' . RandomHelper::hex(8), self::WAREHOUSE_ADDRESS],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a product row of the given kind past the products library, in a category of its own.
     *
     * @param ProductKind $kind Kind of the product
     * @return int Id of the new product
     */
    protected static function product(ProductKind $kind): int
    {
        $slug = 'product-' . RandomHelper::hex(8);
        Database::sql(
            'INSERT INTO product (name, category_id, kind, price, description, picture_file_id, slug, changed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug, self::category(), $kind->value, 12_000, 'Product of the case', self::pictureFile(), $slug, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a category row a product may be put in, with its own picture, past the categories library.
     *
     * @param ?string $name Name of the category, or null for its address
     * @return int Id of the new category
     */
    protected static function category(?string $name = null): int
    {
        $slug = 'category-' . RandomHelper::hex(8);
        $position = (int)Database::sql('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM category')->field('next');
        Database::sql(
            'INSERT INTO category (name, description, slug, position, picture_file_id) VALUES (?, ?, ?, ?, ?)',
            [$name ?? $slug, 'Flowers of the case', $slug, $position, self::pictureFile()],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a registry row a product or a category may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    protected static function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'vase.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }
}
