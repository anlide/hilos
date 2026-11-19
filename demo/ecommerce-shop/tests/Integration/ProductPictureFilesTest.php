<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * What the files library hears of a product's picture, and when: only once the write commits.
 *
 * The always-rollback form cannot show this - an announcement held there is dropped with the
 * root - so these cases commit for real and take their own rows out when they end. The files
 * door is swapped for one that writes each call down beside whether a transaction was still
 * open at that moment. A write the database itself refuses, inside the transaction, is what
 * tells a call made after the commit from one made before it.
 */
final class ProductPictureFilesTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private HilosFiles $door;

    /** @var object{calls: list<array{string, list<int>, bool}>} The swapped door and the calls it wrote down */
    private object $recorder;

    /** Category the case's products are put in, deleted when it ends. */
    private int $categoryId;

    /** @var list<int> Registry rows the case wrote, deleted when it ends */
    private array $fileIds = [];

    /** Last product stored before the case, so that the case takes out only the ones it added. */
    private int $productsBefore;

    /** Last address row stored before the case, so that the case takes out only the ones it added. */
    private int $addressesBefore;

    /**
     * Claims the products, their addresses and the files for the harness, writes the case's category, and
     * declares the products and their addresses complete as their library does on its start.
     *
     * The category goes in past the ORM, so the loaded collections forget what they hold first:
     * a copy of the categories read before would not have it.
     */
    protected function setUp(): void
    {
        parent::setUp();
        TruthSourceRegistry::register(EcommerceShopDbContext::products, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(EcommerceShopDbContext::productSlugs, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::files, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $this->productsBefore = (int)Database::sql('SELECT COALESCE(MAX(id), 0) AS last FROM product')->field('last');
        $this->addressesBefore = (int)Database::sql('SELECT COALESCE(MAX(id), 0) AS last FROM product_slug')->field('last');
        $this->categoryId = $this->category();
        Hilos::$db->forgetLoadedDataForTest();
        $products = Hilos::$db->products->getObjectCollection();
        $addresses = Hilos::$db->productSlugs->getObjectCollection();
        self::assertNotNull($products);
        self::assertNotNull($addresses);
        $products->preloadAll();
        $addresses->preloadAll();

        $door = Hilos::$files;
        self::assertNotNull($door);
        $this->door = $door;
        $this->recorder = new class ($door->storage) extends HilosFiles {
            /** @var list<array{string, list<int>, bool}> Each call: its name, its files, and whether a transaction was open */
            public array $calls = [];

            /** @param list<int> $fileIds Files the product now shows */
            public function markBound(array $fileIds): void
            {
                $this->calls[] = ['markBound', $fileIds, Database::hasOpenTransactions()];
            }

            /** @param list<int> $fileIds Files no product shows any more */
            public function remove(array $fileIds): void
            {
                $this->calls[] = ['remove', $fileIds, Database::hasOpenTransactions()];
            }
        };
        Hilos::$files = $this->recorder;
    }

    /**
     * Puts the door back and takes out every row the case committed, the addresses first.
     *
     * The rows go past the ORM, so the loaded collections forget them too: the next case reads
     * the tables cold.
     */
    protected function tearDown(): void
    {
        Hilos::$files = $this->door;
        Database::sql('DELETE FROM product_slug WHERE id > ?', [$this->addressesBefore]);
        Database::sql('DELETE FROM product WHERE id > ?', [$this->productsBefore]);
        Database::sql('DELETE FROM category WHERE id = ?', [$this->categoryId]);
        foreach ($this->fileIds as $fileId) {
            Database::sql('DELETE FROM hilos_file WHERE id = ?', [$fileId]);
        }
        Hilos::$db->forgetLoadedDataForTest();
        parent::tearDown();
    }

    /** A new product binds its picture once, after the commit. */
    public function testCreateBindsThePictureAfterTheCommit(): void
    {
        $picture = $this->pictureFile();

        $this->createProduct($picture);

        self::assertSame([['markBound', [$picture], false]], $this->recorder->calls);
    }

    /** An edit with a new file binds the new one and removes the former one, both after the commit. */
    public function testAnEditWithANewFileBindsItAndRemovesTheFormerOne(): void
    {
        $former = $this->pictureFile();
        $id = $this->createProduct($former);
        $next = $this->pictureFile();
        $this->recorder->calls = [];

        Hilos::$db->products[$id]->actions->update('Blue vase', $this->categoryId, ProductKind::UNIQUE, 450_000, 'Glazed vase', $next);

        self::assertSame([['markBound', [$next], false], ['remove', [$former], false]], $this->recorder->calls);
    }

    /** An edit without a file says nothing to the files library. */
    public function testAnEditWithoutAFileSaysNothing(): void
    {
        $id = $this->createProduct($this->pictureFile());
        $this->recorder->calls = [];

        Hilos::$db->products[$id]->actions->update('Glazed vase', $this->categoryId, ProductKind::UNIQUE, 520_000, 'Glazed vase', null);

        self::assertSame([], $this->recorder->calls);
    }

    /** An edit that changes nothing, its own picture named again included, says nothing to the files library. */
    public function testAnEditThatChangesNothingSaysNothing(): void
    {
        $picture = $this->pictureFile();
        $id = $this->createProduct($picture);
        $this->recorder->calls = [];

        Hilos::$db->products[$id]->actions->update('Blue vase', $this->categoryId, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', null);
        Hilos::$db->products[$id]->actions->update('Blue vase', $this->categoryId, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $picture);

        self::assertSame([], $this->recorder->calls);
    }

    /** A creation refused before its transaction binds nothing and stores neither a product nor an address. */
    public function testARefusedCreateBindsNothingAndStoresNothing(): void
    {
        $picture = $this->pictureFile();

        try {
            Hilos::$db->products->actions->create('Blue vase', $this->categoryId, ProductKind::UNIQUE, 0, 'Hand-thrown vase', $picture);
            self::fail('A price of zero must be refused');
        } catch (ValidationException $e) {
            self::assertSame('Price must be greater than zero', $e->getMessage());
        }

        self::assertSame([], $this->recorder->calls);
        self::assertSame([0, 0], [$this->storedProductCount(), $this->storedAddressCount()]);
    }

    /**
     * A creation the database refuses inside its transaction - a file the registry does not hold - binds
     * nothing and stores neither a product nor an address.
     */
    public function testACreateTheDatabaseRefusesBindsNothingAndStoresNothing(): void
    {
        $missing = $this->missingFile();

        try {
            Hilos::$db->products->actions->create('Blue vase', $this->categoryId, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $missing);
            self::fail('A file the registry does not hold must be refused by the database');
        } catch (ForeignKeyConstraintException $e) {
            self::assertSame(ForeignKeyConstraintException::class, $e::class);
        }

        self::assertSame([], $this->recorder->calls);
        self::assertSame([0, 0], [$this->storedProductCount(), $this->storedAddressCount()]);
    }

    /**
     * An edit the database refuses inside its transaction - a new file the registry does not hold - binds
     * nothing, removes nothing and leaves the product showing its picture, in the row and in memory.
     */
    public function testAnEditTheDatabaseRefusesBindsNothingAndKeepsThePicture(): void
    {
        $picture = $this->pictureFile();
        $id = $this->createProduct($picture);
        $missing = $this->missingFile();
        $this->recorder->calls = [];

        try {
            Hilos::$db->products[$id]->actions->update('Blue vase', $this->categoryId, ProductKind::UNIQUE, 520_000, 'Glazed vase', $missing);
            self::fail('A file the registry does not hold must be refused by the database');
        } catch (ForeignKeyConstraintException $e) {
            self::assertSame(ForeignKeyConstraintException::class, $e::class);
        }

        self::assertSame([], $this->recorder->calls);
        self::assertSame(
            [['picture_file_id' => $picture, 'price' => 450_000]],
            array_map(
                static fn (array $row): array => ['picture_file_id' => (int)$row['picture_file_id'], 'price' => (int)$row['price']],
                Database::sql('SELECT picture_file_id, price FROM product WHERE id = ?', [$id])->rows(),
            ),
        );
        self::assertSame($picture, Hilos::$db->products[$id]->pictureFileId);
        self::assertSame(450_000, Hilos::$db->products[$id]->price);
    }

    /**
     * Creates a unique piece in the case's category.
     *
     * @param int $picture Registry file the product shows
     * @return int Id of the committed product
     */
    private function createProduct(int $picture): int
    {
        $id = Hilos::$db->products->actions->create('Blue vase', $this->categoryId, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $picture)->id;
        self::assertNotNull($id);

        return $id;
    }

    /**
     * Writes a category row the case's products are put in, with its own picture, past the categories library.
     *
     * @return int Id of the new category
     */
    private function category(): int
    {
        $slug = 'category-' . RandomHelper::hex(8);
        $position = (int)Database::sql('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM category')->field('next');
        Database::sql(
            'INSERT INTO category (name, description, slug, position, picture_file_id) VALUES (?, ?, ?, ?, ?)',
            [$slug, 'Flowers of the case', $slug, $position, $this->pictureFile()],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a registry row a product may show, with no file on disk behind it, and remembers it for the end of the case.
     *
     * @return int Id of the new registry row
     */
    private function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'vase.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );
        $id = Database::lastInsertId();
        $this->fileIds[] = $id;

        return $id;
    }

    /**
     * @return int A file id the registry does not hold
     */
    private function missingFile(): int
    {
        return (int)Database::sql('SELECT COALESCE(MAX(id), 0) + 1 AS missing FROM hilos_file')->field('missing');
    }

    /**
     * @return int Number of products the case stored, read past the ORM
     */
    private function storedProductCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM product WHERE id > ?', [$this->productsBefore])->field('stored');
    }

    /**
     * @return int Number of addresses the case stored, read past the ORM
     */
    private function storedAddressCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM product_slug WHERE id > ?', [$this->addressesBefore])->field('stored');
    }
}
