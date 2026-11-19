<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * What the files library hears of a category's picture, and when: only once the write commits.
 *
 * The always-rollback form cannot show this - an announcement held there is dropped with the
 * root - so these cases commit for real and take their own rows out when they end. The files
 * door is swapped for one that writes each call down beside whether a transaction was still
 * open at that moment.
 */
final class CategoryPictureFilesTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private HilosFiles $door;

    /** @var object{calls: list<array{string, list<int>, bool}>} The swapped door and the calls it wrote down */
    private object $recorder;

    /** @var list<int> Categories the case created, deleted when it ends */
    private array $categoryIds = [];

    /** @var list<int> Registry rows the case wrote, deleted when it ends */
    private array $fileIds = [];

    /** Last address row stored before the case, so that the case takes out only the ones it added. */
    private int $addressesBefore;

    protected function setUp(): void
    {
        parent::setUp();
        TruthSourceRegistry::register(EcommerceShopDbContext::categories, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(EcommerceShopDbContext::categorySlugs, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::files, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $this->addressesBefore = (int)Database::sql('SELECT COALESCE(MAX(id), 0) AS last FROM category_slug')->field('last');

        $door = Hilos::$files;
        self::assertNotNull($door);
        $this->door = $door;
        $this->recorder = new class ($door->storage) extends HilosFiles {
            /** @var list<array{string, list<int>, bool}> Each call: its name, its files, and whether a transaction was open */
            public array $calls = [];

            /** @param list<int> $fileIds Files the category now shows */
            public function markBound(array $fileIds): void
            {
                $this->calls[] = ['markBound', $fileIds, Database::hasOpenTransactions()];
            }

            /** @param list<int> $fileIds Files no category shows any more */
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
        Database::sql('DELETE FROM category_slug WHERE id > ?', [$this->addressesBefore]);
        foreach ($this->categoryIds as $categoryId) {
            Database::sql('DELETE FROM category WHERE id = ?', [$categoryId]);
        }
        foreach ($this->fileIds as $fileId) {
            Database::sql('DELETE FROM hilos_file WHERE id = ?', [$fileId]);
        }
        Hilos::$db->forgetLoadedDataForTest();
        parent::tearDown();
    }

    /** A new category binds its picture once, after the commit. */
    public function testCreateBindsThePictureAfterTheCommit(): void
    {
        $picture = $this->pictureFile();

        $this->createCategory('Roses', $picture);

        self::assertSame([['markBound', [$picture], false]], $this->recorder->calls);
    }

    /** An edit with a new file binds the new one and removes the former one, both after the commit. */
    public function testAnEditWithANewFileBindsItAndRemovesTheFormerOne(): void
    {
        $former = $this->pictureFile();
        $id = $this->createCategory('Roses', $former);
        $next = $this->pictureFile();
        $this->recorder->calls = [];

        Hilos::$db->categories[$id]->actions->update('Roses', 'Garden roses', $next);

        self::assertSame([['markBound', [$next], false], ['remove', [$former], false]], $this->recorder->calls);
    }

    /** A refused edit with a new file binds nothing, removes nothing and records no address. */
    public function testARefusedEditWithANewFileBindsNothingAndRecordsNoAddress(): void
    {
        $this->createCategory('Tulips', $this->pictureFile());
        $id = $this->createCategory('Roses', $this->pictureFile());
        $next = $this->pictureFile();
        $this->recorder->calls = [];
        $addresses = $this->storedAddressCount();

        try {
            Hilos::$db->categories[$id]->actions->update('TULIPS', 'Garden roses', $next);
            self::fail('A taken name must be refused');
        } catch (DuplicateValueException $e) {
            self::assertSame('A category with this name already exists', $e->getMessage());
        }

        self::assertSame([], $this->recorder->calls);
        self::assertSame($addresses, $this->storedAddressCount());
    }

    /** An edit without a file says nothing to the files library. */
    public function testAnEditWithoutAFileSaysNothing(): void
    {
        $id = $this->createCategory('Roses', $this->pictureFile());
        $this->recorder->calls = [];

        Hilos::$db->categories[$id]->actions->update('Red roses', 'Garden roses', null);

        self::assertSame([], $this->recorder->calls);
    }

    /** A deleted category removes its picture, after the commit. */
    public function testDeleteRemovesThePictureAfterTheCommit(): void
    {
        $picture = $this->pictureFile();
        $id = $this->createCategory('Roses', $picture);
        $this->recorder->calls = [];

        Hilos::$db->categories[$id]->actions->delete();

        self::assertSame([['remove', [$picture], false]], $this->recorder->calls);
    }

    /** A refused creation binds nothing and records no address. */
    public function testARefusedCreateBindsNothingAndRecordsNoAddress(): void
    {
        $this->createCategory('Roses', $this->pictureFile());
        $picture = $this->pictureFile();
        $this->recorder->calls = [];
        $addresses = $this->storedAddressCount();

        try {
            Hilos::$db->categories->actions->create('roses', 'Garden roses', $picture);
            self::fail('A taken name must be refused');
        } catch (DuplicateValueException $e) {
            self::assertSame('A category with this name already exists', $e->getMessage());
        }

        self::assertSame([], $this->recorder->calls);
        self::assertSame($addresses, $this->storedAddressCount());
    }

    /**
     * Creates a category and remembers it for the end of the case.
     *
     * @param string $name Category name
     * @param int $picture Registry file the category shows
     * @return int Id of the committed category
     */
    private function createCategory(string $name, int $picture): int
    {
        $id = Hilos::$db->categories->actions->create($name, 'Fresh cut flowers', $picture)->id;
        self::assertNotNull($id);
        $this->categoryIds[] = $id;

        return $id;
    }

    /**
     * Writes a registry row a category may show, with no file on disk behind it, and remembers it for the end of the case.
     *
     * @return int Id of the new registry row
     */
    private function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'roses.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );
        $id = Database::lastInsertId();
        $this->fileIds[] = $id;

        return $id;
    }

    /**
     * @return int Number of stored category addresses, read past the ORM
     */
    private function storedAddressCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM category_slug')->field('stored');
    }
}
