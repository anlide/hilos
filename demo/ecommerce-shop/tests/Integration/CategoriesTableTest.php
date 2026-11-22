<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\Category\CategoriesTable;
use Demo\EcommerceShop\Tables\Category\CategoryTableRow;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The categories table over the real shop schema: its window in catalog order with the picture and the
 * count of products, its search, and the rows a fact of a category or of a product redraws; the rollback
 * scope proves reads of written rows, not commit.
 *
 * Categories are written by their library. Products and pictures are rows written straight into their
 * tables, and products are never read through the ORM here, only counted. The products are not held, as
 * in the categories page's process, so a count goes by SQL on the category column; a walk of the products
 * would be refused.
 * Each fact is built in the shape the ORM announces.
 */
#[IntegrationTest]
final class CategoriesTableTest extends CategoriesTableEnvironment
{
    /** The window carries both picture URLs, the file ID and the count, in catalog order. */
    public function testTheWindowCarriesEveryCategoryInCatalogOrderWithItsPictureAndCount(): void
    {
        $tag = RandomHelper::hex(8);
        $tulipsPicture = self::pictureFile();
        $rosesPicture = self::pictureFile();
        $liliesPicture = self::pictureFile();
        $tulips = Hilos::$db->categories->actions->create("{$tag} Tulips", 'Spring tulips', $tulipsPicture)->id;
        $roses = Hilos::$db->categories->actions->create("{$tag} Roses", 'Fresh cut roses', $rosesPicture)->id;
        $lilies = Hilos::$db->categories->actions->create("{$tag} Lilies", 'White lilies', $liliesPicture)->id;
        Hilos::$db->categories[$lilies]->actions->moveUp();
        self::product(ProductKind::STOCK, $tulips);
        self::product(ProductKind::UNIQUE, $lilies);
        self::product(ProductKind::STOCK, $lilies);

        $table = new CategoriesTable();
        $window = $table->getPage(new TableQueryDTO(sort: $table->defaultSort()));

        self::assertSame(self::storedCategoryCount(), $window->totalCount);
        self::assertSame([
            self::row($tulips, "{$tag} Tulips", 'Spring tulips', $tulipsPicture, 1),
            self::row($lilies, "{$tag} Lilies", 'White lilies', $liliesPicture, 2),
            self::row($roses, "{$tag} Roses", 'Fresh cut roses', $rosesPicture, 0),
        ], self::rowsOf($window->rows, [$tulips, $roses, $lilies]));
    }

    /** The count takes both kinds, a unique piece not yet in a warehouse included, and no product of another category. */
    public function testTheCountTakesEveryProductOfTheCategoryAndNoOther(): void
    {
        $empty = self::category();
        $roses = self::category();
        $tulips = self::category();
        self::product(ProductKind::UNIQUE, $roses);
        self::product(ProductKind::STOCK, $roses);
        self::product(ProductKind::STOCK, $tulips);
        self::assertFalse(Hilos::$db->products->getObjectCollection()?->isAllLoaded());

        $table = new CategoriesTable();

        self::assertSame(0, $table->rowFromCategory(Hilos::$db->categories[$empty])->productCount);
        self::assertSame(2, $table->rowFromCategory(Hilos::$db->categories[$roses])->productCount);
        self::assertFalse(Hilos::$db->products->getObjectCollection()?->isAllLoaded());
    }

    /** A search keeps the categories whose name holds the term, a word only the description holds finds none, and the set answers the same way. */
    public function testASearchKeepsTheCategoriesWhoseNameHoldsTheTermAndNotTheDescription(): void
    {
        $tag = RandomHelper::hex(8);
        $rosesPicture = self::pictureFile();
        $roses = Hilos::$db->categories->actions->create("{$tag} Roses", 'Fresh cut roses', $rosesPicture)->id;
        $tulips = Hilos::$db->categories->actions->create("{$tag} Tulips", "Spring tulips from {$tag}meadow", self::pictureFile())->id;

        $table = new CategoriesTable();
        $query = new TableQueryDTO(search: "{$tag} Ros", sort: $table->defaultSort());
        $window = $table->getPage($query);

        self::assertSame(1, $window->totalCount);
        self::assertSame([self::row($roses, "{$tag} Roses", 'Fresh cut roses', $rosesPicture, 0)], array_map(
            static fn (AbstractTableRow $row): array => $row->toArray(),
            $window->rows,
        ));
        self::assertTrue($table->containsRow($roses, $query));
        self::assertFalse($table->containsRow($tulips, $query));

        self::assertSame(0, $table->getPage(new TableQueryDTO(search: "{$tag}meadow", sort: $table->defaultSort()))->totalCount);
    }

    /** A created product redraws its category once, with one more product. */
    public function testACreatedProductRedrawsItsCategoryWithOneMore(): void
    {
        $roses = self::category();
        self::product(ProductKind::STOCK, $roses);
        $bouquet = self::product(ProductKind::UNIQUE, $roses);

        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::products,
            (string)$bouquet,
            self::announcedProduct($bouquet),
        ));

        self::assertSame([[TableMutationType::Update, $roses, self::storedRow($roses, 2)]], self::described($mutations));
    }

    /** A product moved to another category redraws both: the one it left with one less, the one it came to with one more. */
    public function testAMovedProductRedrawsTheCategoryItLeftAndTheOneItCameTo(): void
    {
        $roses = self::category();
        $tulips = self::category();
        $moved = self::product(ProductKind::STOCK, $roses);
        self::product(ProductKind::STOCK, $roses);
        self::product(ProductKind::UNIQUE, $tulips);

        Database::sql('UPDATE product SET category_id = ? WHERE id = ?', [$tulips, $moved]);
        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::products,
            (string)$moved,
            [EntityProduct::category_id => $tulips],
            previous: [EntityProduct::category_id => $roses],
        ));

        $described = self::described($mutations);
        usort($described, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        self::assertSame([
            [TableMutationType::Update, $roses, self::storedRow($roses, 1)],
            [TableMutationType::Update, $tulips, self::storedRow($tulips, 2)],
        ], $described);
    }

    /** A new price leaves the category alone, so nothing is redrawn. */
    public function testANewPriceRedrawsNothing(): void
    {
        $roses = self::category();
        $bouquet = self::product(ProductKind::STOCK, $roses);

        Database::sql('UPDATE product SET price = ? WHERE id = ?', [15_000, $bouquet]);
        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::products,
            (string)$bouquet,
            [EntityProduct::price => 15_000],
            previous: [EntityProduct::price => 12_000],
        ));

        self::assertSame([], $mutations);
    }

    /** A deleted product, named only by the fact's row, redraws its category with one less. */
    public function testADeletedProductRedrawsItsCategoryWithOneLess(): void
    {
        $roses = self::category();
        $bouquet = self::product(ProductKind::UNIQUE, $roses);
        self::product(ProductKind::STOCK, $roses);
        $announced = self::announcedProduct($bouquet);

        Database::sql('DELETE FROM product WHERE id = ?', [$bouquet]);
        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::products,
            (string)$bouquet,
            $announced,
        ));

        self::assertSame([[TableMutationType::Update, $roses, self::storedRow($roses, 1)]], self::described($mutations));
    }

    /** A product fact naming a category that is gone redraws nothing and raises nothing. */
    public function testAProductFactNamingAVanishedCategoryMutatesNothing(): void
    {
        $gone = self::category();
        Hilos::$db->categories[$gone]->actions->delete();

        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::products,
            '1',
            [
                ObjectProduct::id => 1,
                ObjectProduct::categoryId => $gone,
            ],
        ));

        self::assertSame([], $mutations);
    }

    /** A created category enters the window as a row of its own. */
    public function testACreatedCategoryEntersTheWindowWithItsRow(): void
    {
        $tag = RandomHelper::hex(8);
        $picture = self::pictureFile();
        $roses = Hilos::$db->categories->actions->create("{$tag} Roses", 'Fresh cut roses', $picture)->id;

        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::categories,
            (string)$roses,
            Hilos::$db->categories[$roses]->toArray(),
        ));

        self::assertSame(
            [[TableMutationType::Create, $roses, self::row($roses, "{$tag} Roses", 'Fresh cut roses', $picture, 0)]],
            self::described($mutations),
        );
    }

    /** A renamed category and one given a new picture are each read again by their id and redrawn as they now stand. */
    public function testARenamedCategoryAndANewPictureAreRedrawn(): void
    {
        $tag = RandomHelper::hex(8);
        $picture = self::pictureFile();
        $roses = Hilos::$db->categories->actions->create("{$tag} Roses", 'Fresh cut roses', $picture)->id;
        self::product(ProductKind::STOCK, $roses);
        $table = new CategoriesTable();

        Hilos::$db->categories[$roses]->actions->update("{$tag} Red roses", 'Fresh cut roses', null);
        $renamed = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::categories,
            (string)$roses,
            [EntityCategory::name => "{$tag} Red roses"],
            previous: [EntityCategory::name => "{$tag} Roses"],
        ));
        self::assertSame(
            [[TableMutationType::Update, $roses, self::row($roses, "{$tag} Red roses", 'Fresh cut roses', $picture, 1)]],
            self::described($renamed),
        );

        $next = self::pictureFile();
        Hilos::$db->categories[$roses]->actions->update("{$tag} Red roses", 'Fresh cut roses', $next);
        $repictured = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::categories,
            (string)$roses,
            [EntityCategory::picture_file_id => $next],
            previous: [EntityCategory::picture_file_id => $picture],
        ));
        self::assertSame(
            [[TableMutationType::Update, $roses, self::row($roses, "{$tag} Red roses", 'Fresh cut roses', $next, 1)]],
            self::described($repictured),
        );
    }

    /** A category moved up swaps places with the one before it, and the two facts redraw both at their new places. */
    public function testAMoveUpRedrawsBothCategoriesAtTheirNewPlaces(): void
    {
        $roses = self::category();
        $tulips = self::category();
        $rosesPlace = self::storedPosition($roses);
        $tulipsPlace = self::storedPosition($tulips);
        $rosesFirstBefore = self::storedRow($roses, 0)[CategoryTableRow::first];
        $table = new CategoriesTable();

        Hilos::$db->categories[$tulips]->actions->moveUp();
        $mutations = [
            ...$table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
                EcommerceShopDbContext::categories,
                (string)$roses,
                [EntityCategory::position => $tulipsPlace],
                previous: [EntityCategory::position => $rosesPlace],
            )),
            ...$table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
                EcommerceShopDbContext::categories,
                (string)$tulips,
                [EntityCategory::position => $rosesPlace],
                previous: [EntityCategory::position => $tulipsPlace],
            )),
        ];

        self::assertSame([
            [TableMutationType::Update, $roses, self::storedRow($roses, 0)],
            [TableMutationType::Update, $tulips, self::storedRow($tulips, 0)],
        ], self::described($mutations));
        self::assertSame([$tulipsPlace, $rosesPlace], [
            $mutations[0]->row?->toArray()[CategoryTableRow::position],
            $mutations[1]->row?->toArray()[CategoryTableRow::position],
        ]);
        self::assertSame($rosesFirstBefore, $mutations[1]->row?->toArray()[CategoryTableRow::first]);
        self::assertFalse($mutations[0]->row?->toArray()[CategoryTableRow::first]);
    }

    /** A deleted empty category leaves the window by its key. */
    public function testADeletedCategoryLeavesTheWindow(): void
    {
        $gone = self::category();
        $announced = Hilos::$db->categories[$gone]->toArray();

        Hilos::$db->categories[$gone]->actions->delete();
        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::categories,
            (string)$gone,
            $announced,
        ));

        self::assertSame([[TableMutationType::Delete, $gone, null]], self::described($mutations));
    }

    /** A search hiding the first category shows rows whose first flag is false. */
    public function testASearchHidingTheFirstCategoryShowsRowsWithFirstFalse(): void
    {
        $tag = RandomHelper::hex(8);
        $first = Hilos::$db->categories->actions->create("{$tag} First", 'First category', self::pictureFile())->id;
        $second = Hilos::$db->categories->actions->create("{$tag} Second", 'Second category', self::pictureFile())->id;

        $table = new CategoriesTable();
        $query = new TableQueryDTO(search: "{$tag} Second", sort: $table->defaultSort());
        $window = $table->getPage($query);

        self::assertSame(1, $window->totalCount);
        $row = $window->rows[0]->toArray();
        self::assertSame($second, $row[CategoryTableRow::id]);
        self::assertFalse($row[CategoryTableRow::first]);
    }

    /** Deleting the first category produces a delete mutation and an update mutation for the category that becomes first. */
    public function testDeletingTheFirstCategoryUpdatesTheNewFirstCategory(): void
    {
        $first = self::category();
        $second = self::category();
        while (true) {
            try {
                Hilos::$db->categories[$first]->actions->moveUp();
            } catch (ValidationException) {
                break;
            }
        }
        while (true) {
            try {
                Hilos::$db->categories[$second]->actions->moveUp();
                if (Hilos::$db->categories[$second]->position < Hilos::$db->categories[$first]->position) {
                    Hilos::$db->categories[$first]->actions->moveUp();
                    break;
                }
            } catch (ValidationException) {
                break;
            }
        }

        $announced = Hilos::$db->categories[$first]->toArray();
        Hilos::$db->categories[$first]->actions->delete();
        $mutations = (new CategoriesTable())->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::categories,
            (string)$first,
            $announced,
        ));

        self::assertSame([
            [TableMutationType::Delete, $first, null],
            [TableMutationType::Update, $second, self::storedRow($second, 0)],
        ], self::described($mutations));
        self::assertTrue($mutations[1]->row?->toArray()[CategoryTableRow::first]);
    }

    /**
     * @param int $id Category id
     * @param string $name Category name
     * @param string $description Category description
     * @param int $picture Registry file the category shows
     * @param int $productCount Products of the category
     * @return array<string, int|string|bool> The row payload the table should carry for the category, at its stored place
     */
    private static function row(int $id, string $name, string $description, int $picture, int $productCount): array
    {
        return [
            CategoryTableRow::id => $id,
            CategoryTableRow::name => $name,
            CategoryTableRow::description => $description,
            CategoryTableRow::position => self::storedPosition($id),
            CategoryTableRow::picture => HilosFiles::downloadPath($picture, CatalogPictureUploadTarget::VARIANT_XS),
            CategoryTableRow::pictureFileId => $picture,
            CategoryTableRow::picturePreview => HilosFiles::downloadPath($picture, CatalogPictureUploadTarget::VARIANT_SM),
            CategoryTableRow::productCount => $productCount,
            CategoryTableRow::first => self::storedPosition($id) === self::storedLowestPosition(),
        ];
    }

    /**
     * @param int $id Category id
     * @param int $productCount Products of the category
     * @return array<string, int|string> The row payload the table should carry for a category the case wrote with {@see category()}
     */
    private static function storedRow(int $id, int $productCount): array
    {
        $stored = Database::sql('SELECT * FROM category WHERE id = ?', [$id])->rows()[0];

        return self::row(
            $id,
            (string)$stored[EntityCategory::name],
            (string)$stored[EntityCategory::description],
            (int)$stored[EntityCategory::picture_file_id],
            $productCount,
        );
    }

    /**
     * @param list<AbstractTableRow> $rows Rows of a window
     * @param list<int> $ids Categories of the case
     * @return list<array<string, mixed>> Payloads of the case's categories, in the window's order
     */
    private static function rowsOf(array $rows, array $ids): array
    {
        $payloads = [];
        foreach ($rows as $row) {
            if (in_array($row->getRowKey(), $ids, true)) {
                $payloads[] = $row->toArray();
            }
        }

        return $payloads;
    }

    /**
     * @param list<TableRowMutationDTO> $mutations Mutations a fact produced
     * @return list<array{TableMutationType, int|string, ?array<string, mixed>}> Each mutation's type, row key and row payload
     */
    private static function described(array $mutations): array
    {
        return array_map(
            static fn (TableRowMutationDTO $mutation): array => [$mutation->type, $mutation->rowKey, $mutation->row?->toArray()],
            $mutations,
        );
    }

    /**
     * Writes a category through its library, under a name of its own.
     *
     * @return int Id of the new category
     */
    private static function category(): int
    {
        return Hilos::$db->categories->actions->create('Category ' . RandomHelper::hex(8), 'Flowers of the case', self::pictureFile())->id;
    }

    /**
     * Writes a product row of the given kind into the given category, past the products library.
     *
     * @param ProductKind $kind Kind of the product
     * @param int $categoryId Category the product is put in
     * @return int Id of the new product
     */
    private static function product(ProductKind $kind, int $categoryId): int
    {
        $slug = 'product-' . RandomHelper::hex(8);
        Database::sql(
            'INSERT INTO product (name, category_id, kind, price, description, picture_file_id, slug, changed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug, $categoryId, $kind->value, 12_000, 'Product of the case', self::pictureFile(), $slug, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $productId Product id
     * @return array<string, int|string> The stored product row, read past the ORM, in the keys its Object announces it in
     */
    private static function announcedProduct(int $productId): array
    {
        $stored = Database::sql('SELECT * FROM product WHERE id = ?', [$productId])->rows()[0];

        return [
            ObjectProduct::id => (int)$stored[EntityProduct::id],
            ObjectProduct::name => (string)$stored[EntityProduct::name],
            ObjectProduct::categoryId => (int)$stored[EntityProduct::category_id],
            ObjectProduct::kind => (string)$stored[EntityProduct::kind],
            ObjectProduct::price => (int)$stored[EntityProduct::price],
            ObjectProduct::description => (string)$stored[EntityProduct::description],
            ObjectProduct::pictureFileId => (int)$stored[EntityProduct::picture_file_id],
            ObjectProduct::slug => (string)$stored[EntityProduct::slug],
            ObjectProduct::changedAt => (int)$stored[EntityProduct::changed_at],
        ];
    }

    /**
     * Writes a registry row a category or a product may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    private static function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'roses.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $id Category id
     * @return int The stored place of the category in the catalog, read past the ORM
     */
    private static function storedPosition(int $id): int
    {
        return (int)Database::sql('SELECT position FROM category WHERE id = ?', [$id])->field(EntityCategory::position);
    }

    /**
     * @return ?int The lowest stored position among all categories, read past the ORM
     */
    private static function storedLowestPosition(): ?int
    {
        $val = Database::sql('SELECT MIN(position) AS min_pos FROM category')->field('min_pos');

        return $val === null ? null : (int)$val;
    }

    /**
     * @return int Number of stored categories, read past the ORM
     */
    private static function storedCategoryCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM category')->field('stored');
    }
}
