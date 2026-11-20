<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\ProductFields;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Files\FileVisibility;
use Hilos\HilosException;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The real shop schema and the product actions under their library's claim; the rollback scope proves writes, not commit.
 *
 * A category and a picture are rows written straight into `category` and `hilos_file`, with no
 * file on disk, and each case writes its categories before its first action: the categories are
 * read whole on their first read, and the rollback forgets that read with the rows. What the files
 * library hears after a commit is {@see ProductPictureFilesTest}'s to prove.
 */
#[IntegrationTest]
final class ProductsLibraryTest extends ProductsLibraryEnvironment
{
    /** The library's start declares the products and their addresses complete in its process. */
    public function testTheStartDeclaresTheProductsAndTheirAddressesComplete(): void
    {
        $products = Hilos::$db->products->getObjectCollection();
        $addresses = Hilos::$db->productSlugs->getObjectCollection();

        self::assertNotNull($products);
        self::assertNotNull($addresses);
        self::assertTrue($products->isAllLoaded());
        self::assertTrue($addresses->isAllLoaded());
    }

    /** A unique piece and a stock item are both stored trimmed, under the address of their name, at the moment of the write. */
    public function testCreateStoresEitherKindTrimmedUnderTheAddressOfItsName(): void
    {
        $category = self::category();
        $vase = self::pictureFile();
        $bulbs = self::pictureFile();

        $before = TimeHelper::nowMs();
        $unique = Hilos::$db->products->actions->create(
            '  Blue vase  ',
            $category,
            ProductKind::UNIQUE,
            450_000,
            "\tHand-thrown vase \n",
            $vase,
        );
        $stock = Hilos::$db->products->actions->create('Tulip bulbs', $category, ProductKind::STOCK, 12_000, 'Ten bulbs', $bulbs);
        $after = TimeHelper::nowMs();

        self::assertNotNull($unique->id);
        self::assertNotNull($stock->id);
        $uniqueRow = self::storedRow($unique->id);
        $stockRow = self::storedRow($stock->id);
        foreach ([$uniqueRow, $stockRow] as $row) {
            self::assertGreaterThanOrEqual($before, $row['changedAt']);
            self::assertLessThanOrEqual($after, $row['changedAt']);
        }
        self::assertSame([
            'name' => 'Blue vase',
            'categoryId' => $category,
            'kind' => 'unique',
            'price' => 450_000,
            'description' => 'Hand-thrown vase',
            'pictureFileId' => $vase,
            'slug' => 'blue-vase',
            'changedAt' => $uniqueRow['changedAt'],
        ], $uniqueRow);
        self::assertSame([
            'name' => 'Tulip bulbs',
            'categoryId' => $category,
            'kind' => 'stock',
            'price' => 12_000,
            'description' => 'Ten bulbs',
            'pictureFileId' => $bulbs,
            'slug' => 'tulip-bulbs',
            'changedAt' => $stockRow['changedAt'],
        ], $stockRow);
        self::assertSame(['blue-vase' => $unique->id, 'tulip-bulbs' => $stock->id], self::storedAddresses());
    }

    /** An edit naming the other kind is refused, and the product stays as it was. */
    public function testAnEditCannotChangeTheKind(): void
    {
        $id = self::createProduct('Blue vase', self::category());
        $stored = self::storedRow($id);

        self::assertRefused(
            ValidationException::class,
            'A product keeps the kind it was created with',
            static fn () => Hilos::$db->products[$id]->actions->update(
                'Blue vase',
                $stored['categoryId'],
                ProductKind::STOCK,
                450_000,
                'Hand-thrown vase',
                null,
            ),
        );

        self::assertUnchanged($id, $stored);
    }

    /** A product with no category chosen, or with one deleted meanwhile, is refused, and nothing is stored. */
    public function testAProductWithoutAnExistingCategoryIsRefused(): void
    {
        $missing = (int)Database::sql('SELECT COALESCE(MAX(id), 0) + 1 AS missing FROM category')->field('missing');
        $picture = self::pictureFile();
        $before = self::storedCount();

        self::assertRefused(
            EmptyValueException::class,
            'Choose a category for the product',
            static fn () => Hilos::$db->products->actions->create('Blue vase', null, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $picture),
        );
        self::assertRefused(
            ValidationException::class,
            'This category no longer exists',
            static fn () => Hilos::$db->products->actions->create('Blue vase', $missing, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $picture),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame([], self::storedAddresses());
    }

    /** The address keeps the letters and digits of the name; a name is not unique, and the second one gets the next number. */
    public function testTheAddressIsBuiltFromTheName(): void
    {
        $category = self::category();

        $first = self::createProduct('Clay pot 12 cm', $category);
        $second = self::createProduct('Clay pot 12 cm', $category);
        $blossom = self::createProduct('🌸', $category);

        self::assertSame('clay-pot-12-cm', self::storedRow($first)['slug']);
        self::assertSame('clay-pot-12-cm-2', self::storedRow($second)['slug']);
        self::assertSame('Clay pot 12 cm', self::storedRow($second)['name']);
        self::assertSame('product', self::storedRow($blossom)['slug']);
    }

    /** A rename gives a new address and keeps the old one; renamed back, the old address is current again and nothing is added. */
    public function testARenameKeepsTheFormerAddressAndARenameBackTakesItAgain(): void
    {
        $category = self::category();
        $id = self::createProduct('Clay pot', $category);

        self::rename($id, 'Glazed pot');

        self::assertSame('glazed-pot', self::storedRow($id)['slug']);
        self::assertSame(['clay-pot' => $id, 'glazed-pot' => $id], self::storedAddresses());

        self::rename($id, 'Clay pot');

        self::assertSame('clay-pot', self::storedRow($id)['slug']);
        self::assertSame(['clay-pot' => $id, 'glazed-pot' => $id], self::storedAddresses());
    }

    /** A rename that leaves the address of the name as it was keeps the address and adds none. */
    public function testARenameWithTheSameAddressKeepsIt(): void
    {
        $id = self::createProduct('Clay pot', self::category());

        self::rename($id, 'Clay pot!');

        self::assertSame('Clay pot!', self::storedRow($id)['name']);
        self::assertSame('clay-pot', self::storedRow($id)['slug']);
        self::assertSame(['clay-pot' => $id], self::storedAddresses());
    }

    /** The former address of another product is never given again: a new product of that name gets the next number. */
    public function testTheFormerAddressOfAnotherProductIsNotGivenAgain(): void
    {
        $category = self::category();
        $first = self::createProduct('Clay pot', $category);
        self::rename($first, 'Glazed pot');

        $second = self::createProduct('Clay pot', $category);

        self::assertSame('clay-pot-2', self::storedRow($second)['slug']);
        self::assertSame(['clay-pot' => $first, 'glazed-pot' => $first, 'clay-pot-2' => $second], self::storedAddresses());
    }

    /**
     * An edit moves the product to another category, reprices it, redescribes it and shows a new file when given one,
     * and stamps the moment of the edit; an edit without a file keeps the picture.
     *
     * The moment is moved back through the object first, not by SQL: the save writes only the columns
     * that differ from the copy in memory, which an SQL shift would not touch, so an edit in the very
     * millisecond of the creation would stamp the copy's own value, write nothing, and leave the
     * moved-back value. Through the object the copy and the row agree on the earlier moment, and an
     * edit that stamps nothing leaves it below the moment taken before the call.
     */
    public function testAnEditChangesTheFieldsAndTheMomentAndKeepsThePictureWithoutANewFile(): void
    {
        $roses = self::category();
        $tulips = self::category();
        $former = self::pictureFile();
        $id = Hilos::$db->products->actions->create('Blue vase', $roses, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $former)->id;
        self::assertNotNull($id);
        $next = self::pictureFile();
        self::moveMomentBack($id);

        $before = TimeHelper::nowMs();
        Hilos::$db->products[$id]->actions->update('Blue vase', $tulips, ProductKind::UNIQUE, 520_000, 'Glazed vase', $next);
        $after = TimeHelper::nowMs();

        $stored = self::storedRow($id);
        self::assertSame(
            [$tulips, 520_000, 'Glazed vase', $next],
            [$stored['categoryId'], $stored['price'], $stored['description'], $stored['pictureFileId']],
        );
        self::assertGreaterThanOrEqual($before, $stored['changedAt']);
        self::assertLessThanOrEqual($after, $stored['changedAt']);

        Hilos::$db->products[$id]->actions->update('Blue vase', $tulips, ProductKind::UNIQUE, 520_000, 'Glazed blue vase', null);

        self::assertSame($next, self::storedRow($id)['pictureFileId']);
        self::assertSame('Glazed blue vase', self::storedRow($id)['description']);
    }

    /**
     * An edit that changes nothing writes nothing: the row stays as it was, its moment included.
     *
     * The moment is moved back through the object, so that the copy in memory and the row agree on it:
     * an edit that stamped anyway would then differ from the copy in every millisecond, and its write
     * could not hide behind a stamp equal to the creation's.
     */
    public function testAnEditThatChangesNothingKeepsTheRowAndItsMoment(): void
    {
        $category = self::category();
        $picture = self::pictureFile();
        $id = Hilos::$db->products->actions->create('Blue vase', $category, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $picture)->id;
        self::assertNotNull($id);
        self::moveMomentBack($id);
        $stored = self::storedRow($id);

        Hilos::$db->products[$id]->actions->update('  Blue vase ', $category, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $picture);
        Hilos::$db->products[$id]->actions->update('Blue vase', $category, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', null);

        self::assertSame(1000, self::storedRow($id)['changedAt']);
        self::assertSame($stored, self::storedRow($id));
        self::assertSame(['blue-vase' => $id], self::storedAddresses());
    }

    /** A new product with an empty, too long or out-of-range field is refused in words the person reads, and nothing is stored. */
    public function testACreateWithABadFieldIsRefusedAndNothingIsStored(): void
    {
        $category = self::category();
        $picture = self::pictureFile();
        $before = self::storedCount();

        foreach (self::refusals() as [$expected, $sentence, $name, $price, $description]) {
            self::assertRefused(
                $expected,
                $sentence,
                static fn () => Hilos::$db->products->actions->create($name, $category, ProductKind::STOCK, $price, $description, $picture),
            );
        }

        self::assertSame($before, self::storedCount());
        self::assertSame([], self::storedAddresses());
    }

    /** An edit with an empty, too long or out-of-range field is refused in words the person reads, and the product stays as it was. */
    public function testAnEditWithABadFieldIsRefusedAndKeepsTheProduct(): void
    {
        $category = self::category();
        $id = self::createProduct('Blue vase', $category);
        $stored = self::storedRow($id);
        $next = self::pictureFile();

        foreach (self::refusals() as [$expected, $sentence, $name, $price, $description]) {
            self::assertRefused(
                $expected,
                $sentence,
                static fn () => Hilos::$db->products[$id]->actions->update($name, $category, ProductKind::UNIQUE, $price, $description, $next),
            );
        }

        self::assertUnchanged($id, $stored);
        self::assertSame(['blue-vase' => $id], self::storedAddresses());
    }

    /** An agent other than the library may neither create a product nor edit one. */
    public function testAWriterOtherThanTheLibraryIsRefused(): void
    {
        $category = self::category();
        $id = self::createProduct('Blue vase', $category);
        $stored = self::storedRow($id);
        $picture = self::pictureFile();
        $before = self::storedCount();

        self::assertRefusedToAnotherWriter(
            CreateNotAllowedException::class,
            static fn () => Hilos::$db->products->actions->create('Tulip bulbs', $category, ProductKind::STOCK, 12_000, 'Ten bulbs', $picture),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->products[$id]->actions->update('Glazed vase', $category, ProductKind::UNIQUE, 520_000, 'Glazed vase', null),
        );

        self::assertSame($before, self::storedCount());
        self::assertUnchanged($id, $stored);
    }

    /**
     * @return list<array{class-string<ValidationException>, string, string, int, string}> Each refused field: the refusal,
     *     its sentence, and the name, price and description that bring it
     */
    private static function refusals(): array
    {
        return [
            [EmptyValueException::class, 'Product name cannot be empty', '   ', 450_000, 'Hand-thrown vase'],
            [EmptyValueException::class, 'Product description cannot be empty', 'Blue vase', 450_000, '   '],
            [
                ValueTooLongException::class,
                'Product name exceeds maximum length of 100 characters',
                str_repeat('n', 101),
                450_000,
                'Hand-thrown vase',
            ],
            [
                ValueTooLongException::class,
                'Product description exceeds maximum length of 1000 characters',
                'Blue vase',
                450_000,
                str_repeat('d', 1001),
            ],
            [ValidationException::class, 'Price must be greater than zero', 'Blue vase', 0, 'Hand-thrown vase'],
            [ValidationException::class, 'Price cannot be more than ₴999,999.99', 'Blue vase', ProductFields::PRICE_MAX + 1, 'Hand-thrown vase'],
        ];
    }

    /**
     * Creates a unique piece of this name with a fresh picture.
     *
     * @param string $name Product name
     * @param int $category Category the product is put in
     * @return int Id of the new product
     */
    private static function createProduct(string $name, int $category): int
    {
        $id = Hilos::$db->products->actions->create($name, $category, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', self::pictureFile())->id;
        self::assertNotNull($id);

        return $id;
    }

    /**
     * Renames a product and leaves the rest of it as it is.
     *
     * @param int $id Product id
     * @param string $name New name
     */
    private static function rename(int $id, string $name): void
    {
        $product = Hilos::$db->products[$id];
        $product->actions->update($name, $product->categoryId, $product->kind, $product->price, $product->description, null);
    }

    /**
     * Checks a refused write left the product of its case as it was: the row and the collection.
     *
     * @param int $id Product id
     * @param array{name: string, categoryId: int, kind: string, price: int, description: string, pictureFileId: int, slug: string,
     *     changedAt: int} $stored The row read before the refused write
     */
    private static function assertUnchanged(int $id, array $stored): void
    {
        self::assertSame($stored, self::storedRow($id));
        $product = Hilos::$db->products[$id];
        self::assertSame($stored, [
            'name' => $product->name,
            'categoryId' => $product->categoryId,
            'kind' => $product->kind->value,
            'price' => $product->price,
            'description' => $product->description,
            'pictureFileId' => $product->pictureFileId,
            'slug' => $product->slug,
            'changedAt' => $product->changedAt,
        ]);
    }

    /**
     * Runs a write that must be refused, and checks the refusal reaches the person word for word.
     *
     * @param class-string<ValidationException> $expected Exact refusal class
     * @param string $sentence The sentence the person is shown
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefused(string $expected, string $sentence, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($sentence, $e->getMessage());
            self::assertSame($sentence, ActionFailureReason::forClient($e));

            return;
        }

        self::fail("Expected {$expected}: {$sentence}");
    }

    /**
     * Runs a write as the shop's app agent, which does not own the products, and checks it is refused.
     *
     * @param class-string<HilosException> $expected Exact refusal class
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefusedToAnotherWriter(string $expected, callable $write): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), $write);
        } catch (HilosException $e) {
            self::assertSame($expected, $e::class);

            return;
        }

        self::fail("Expected {$expected} for a writer other than the products library");
    }

    /**
     * Writes a category row a product may be put in, with its own picture, past the categories library.
     *
     * @return int Id of the new category
     */
    private static function category(): int
    {
        $slug = 'category-' . RandomHelper::hex(8);
        $position = (int)Database::sql('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM category')->field('next');
        Database::sql(
            'INSERT INTO category (name, description, slug, position, picture_file_id) VALUES (?, ?, ?, ?, ?)',
            [$slug, 'Flowers of the case', $slug, $position, self::pictureFile()],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a registry row a product may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    private static function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'vase.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * Moves the moment of an edit back to a long-past value through the object, so the copy in memory and the row agree on it.
     *
     * @param int $id Product id
     */
    private static function moveMomentBack(int $id): void
    {
        $product = Hilos::$db->products->getObjectCollection()?->get($id);
        self::assertNotNull($product);
        $product->changedAt = 1000;
        $product->sync();
    }

    /**
     * @param int $id Product id
     * @return array{name: string, categoryId: int, kind: string, price: int, description: string, pictureFileId: int, slug: string,
     *     changedAt: int} The stored product read past the ORM
     */
    private static function storedRow(int $id): array
    {
        $rows = Database::sql(
            'SELECT name, category_id, kind, price, description, picture_file_id, slug, changed_at FROM product WHERE id = ?',
            [$id],
        )->rows();
        self::assertCount(1, $rows);

        return [
            'name' => (string)$rows[0]['name'],
            'categoryId' => (int)$rows[0]['category_id'],
            'kind' => (string)$rows[0]['kind'],
            'price' => (int)$rows[0]['price'],
            'description' => (string)$rows[0]['description'],
            'pictureFileId' => (int)$rows[0]['picture_file_id'],
            'slug' => (string)$rows[0]['slug'],
            'changedAt' => (int)$rows[0]['changed_at'],
        ];
    }

    /**
     * @return array<string, int> Every stored address, in the order it was recorded, to its product
     */
    private static function storedAddresses(): array
    {
        $addresses = [];
        foreach (Database::sql('SELECT slug, product_id FROM product_slug ORDER BY id')->rows() as $row) {
            $addresses[(string)$row['slug']] = (int)$row['product_id'];
        }

        return $addresses;
    }

    /**
     * @return int Number of stored products, read past the ORM
     */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM product')->field('stored');
    }
}
