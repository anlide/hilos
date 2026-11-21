<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Category;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\View\Item\Category;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;
use Hilos\Files\HilosFiles;

/**
 * The shop's categories in catalog order, each with the small copy of its picture, how many products it holds,
 * and whether it is the first in the catalog.
 *
 * The categories are a few rows, so the table reads them whole and searches and orders them in
 * memory. The count is asked again whenever a product fact names a category; who may open the
 * table is decided by the page that binds it.
 */
final class CategoriesTable extends TableDefinition implements MultiRowViewportTable
{
    /** Wire slot the row payload rides under. */
    public const string ROW_SLOT = 'category';

    /**
     * The second row only watches: it makes a page process interested in the products, so that
     * their facts reach the window and recount the categories they name.
     */
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_CATEGORIES,
            EcommerceShopBrowserSource::DB_PRODUCTS,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_CATEGORIES,
                BrowserTableFieldKey::ROW_KEY => ObjectCategory::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectCategory::id => CategoryTableRow::id,
                    ObjectCategory::name => CategoryTableRow::name,
                    ObjectCategory::description => CategoryTableRow::description,
                    ObjectCategory::position => CategoryTableRow::position,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    CategoryTableRow::picture,
                    CategoryTableRow::productCount,
                    CategoryTableRow::first,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCTS,
                BrowserTableFieldKey::ROW_KEY => ObjectProduct::categoryId,
                BrowserTableFieldKey::FIELDS => [
                    ObjectProduct::id,
                    ObjectProduct::categoryId,
                ],
            ],
        ],
    ];

    /**
     * @return ?TableSortOrderDTO First window in catalog order, by position ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(CategoryTableRow::position));
    }

    /**
     * A change of the categories collection redraws that category; anything else is ignored.
     *
     * A deletion names the removed row's id. A create or an update is read back by the row's
     * id, and a row that is already gone is ignored.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Category row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a category or the count of its products cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== EcommerceShopDbContext::categories) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, (int) $change->sourceId);
        }

        if (!isset(Hilos::$db->categories[(int) $change->sourceId])) {
            return null;
        }
        $category = Hilos::$db->categories[(int) $change->sourceId];

        return $this->mutation($change->mutationType, $category->id, $this->rowFromCategory($category));
    }

    /**
     * A product fact redraws every category it names, in either row vocabulary.
     *
     * Only the fact is read, never the product's current row: a created product names its
     * category in the whole row, a moved one names the category it left in the previous row and
     * the one it came to in the row, and a deleted one names its category in the row it had. An
     * edit that leaves the category alone names none and redraws nothing.
     *
     * @param SourceChange $change Source fact from the categories or the products
     * @return list<TableRowMutationDTO> Current category rows, without duplicates or vanished categories
     * @throws DatabaseException When a category or the count of its products cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if ($change->kind !== SourceChange::KIND_DB) {
            return [];
        }
        if ($change->sourceKey === EcommerceShopDbContext::categories) {
            $mutation = $this->buildMutationForSourceEvent($change);
            if ($mutation === null) {
                return [];
            }
            if ($change->mutationType !== TableMutationType::Delete) {
                return [$mutation];
            }

            $firstPosition = $this->firstPosition();
            if ($firstPosition === null) {
                return [$mutation];
            }

            $result = Hilos::$db->categories->queryPageItems(new TableQueryDTO());
            /** @var list<Category> $rows */
            $rows = $result[TableConstants::RESULT_KEY_ROWS];
            $firstCategory = null;
            foreach ($rows as $category) {
                if ($category->position === $firstPosition) {
                    $firstCategory = $category;
                    break;
                }
            }
            if ($firstCategory === null) {
                return [$mutation];
            }

            return [
                $mutation,
                $this->mutation(
                    TableMutationType::Update,
                    $firstCategory->id,
                    $this->rowFromCategory($firstCategory),
                ),
            ];
        }
        if ($change->sourceKey !== EcommerceShopDbContext::products) {
            return [];
        }

        $categoryIds = [];
        foreach ([$change->row, $change->previous] as $row) {
            $id = $row[ObjectProduct::categoryId] ?? $row[EntityProduct::category_id] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $categoryIds[(int) $id] = true;
            }
        }

        $mutations = [];
        foreach (array_keys($categoryIds) as $categoryId) {
            if (isset(Hilos::$db->categories[$categoryId])) {
                $category = Hilos::$db->categories[$categoryId];
                $mutations[] = $this->mutation(
                    TableMutationType::Update,
                    $category->id,
                    $this->rowFromCategory($category),
                );
            }
        }

        return $mutations;
    }

    /**
     * @param AbstractTableRow $row Category row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * The four stored fields are columns of category, none of them personal; the picture's
     * address is built from the category's picture column. The count is a computed, nonpersonal fact.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            CategoryTableRow::id => WireField::column(EcommerceShopDbContext::categories, ObjectCategory::id),
            CategoryTableRow::name => WireField::column(EcommerceShopDbContext::categories, ObjectCategory::name),
            CategoryTableRow::description => WireField::column(EcommerceShopDbContext::categories, ObjectCategory::description),
            CategoryTableRow::position => WireField::column(EcommerceShopDbContext::categories, ObjectCategory::position),
            CategoryTableRow::picture => WireField::column(EcommerceShopDbContext::categories, ObjectCategory::pictureFileId),
            CategoryTableRow::productCount => WireField::notPersonal(),
            CategoryTableRow::first => WireField::notPersonal(),
        ];
    }

    /**
     * Whether the category is in the set this window would show.
     *
     * The one category is searched the same way the window is, so a new category under an
     * active search is announced only when the search would keep it.
     *
     * @param string|int $rowKey Decimal category id
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the category is in the set; this table always knows
     * @throws DatabaseException When the category or the count of its products cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        $id = filter_var($rowKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || (string) $id !== (string) $rowKey) {
            return false;
        }
        if (!isset(Hilos::$db->categories[$id])) {
            return false;
        }
        $category = Hilos::$db->categories[$id];

        return $this->containsRowInMemory(
            [$this->rowFromCategory($category)->toArray()],
            (string) $category->id,
            $this->scopeSearch($query),
        );
    }

    /**
     * Finds the lowest catalog position among all categories, or null when the catalog is empty.
     *
     * @return ?int Lowest catalog position, or null
     * @throws DatabaseException When categories cannot be read
     * @throws LogicException When the categories collection is not configured
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    private function firstPosition(): ?int
    {
        $result = Hilos::$db->categories->queryPageItems(new TableQueryDTO());
        /** @var list<Category> $rows */
        $rows = $result[TableConstants::RESULT_KEY_ROWS];
        if ($rows === []) {
            return null;
        }

        $minPosition = null;
        foreach ($rows as $category) {
            if ($minPosition === null || $category->position < $minPosition) {
                $minPosition = $category->position;
            }
        }

        return $minPosition;
    }

    /**
     * Builds category row payload with the given lowest catalog position.
     *
     * @param Category $category Category to project
     * @param ?int $firstPosition Lowest catalog position among all categories, or null
     * @return CategoryTableRow The category row with picture, product count, and first indicator
     * @throws DatabaseException When the count of its products cannot be read
     * @throws LogicException When the products collection is not configured
     * @throws InvalidArgumentException When the picture variant is undeclared or the count's column is invalid
     */
    private function buildRow(Category $category, ?int $firstPosition): CategoryTableRow
    {
        return new CategoryTableRow(
            id: $category->id,
            name: $category->name,
            description: $category->description,
            position: $category->position,
            picture: HilosFiles::downloadPath($category->pictureFileId, CatalogPictureUploadTarget::VARIANT_XS),
            productCount: Hilos::$db->products->countInCategory($category->id),
            first: $firstPosition !== null && $category->position === $firstPosition,
        );
    }

    /**
     * @param Category $category Category to project
     * @return CategoryTableRow The category with the address of its small picture, count of its products, and first indicator
     * @throws DatabaseException When the categories or the count of its products cannot be read
     * @throws LogicException When the categories or products collection is not configured
     * @throws InvalidArgumentException When the picture variant is undeclared or the count's column is invalid
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    public function rowFromCategory(Category $category): CategoryTableRow
    {
        return $this->buildRow($category, $this->firstPosition());
    }

    /**
     * Every category, read from the database rather than from what this process holds, searched and ordered in memory.
     *
     * @param TableQueryDTO $query Window query, its search already scoped
     * @return TableSnapshotDTO Categories window
     * @throws DatabaseException When the categories or the counts of their products cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $result = Hilos::$db->categories->queryPageItems(new TableQueryDTO());
        /** @var list<Category> $rows */
        $rows = $result[TableConstants::RESULT_KEY_ROWS];

        $firstPosition = null;
        foreach ($rows as $category) {
            if ($firstPosition === null || $category->position < $firstPosition) {
                $firstPosition = $category->position;
            }
        }

        return $this->filterInMemory(
            array_map(
                fn (Category $category): array => $this->buildRow($category, $firstPosition)->toArray(),
                $rows,
            ),
            $query,
        );
    }

    /**
     * @return array<string, string> The place in the catalog, ordered by itself
     */
    protected function sortableFields(): array
    {
        return [
            CategoryTableRow::position => CategoryTableRow::position,
        ];
    }

    /**
     * @return array<string, string> The name, searched as a substring in memory; the description is not searched
     */
    protected function searchableFields(): array
    {
        return [
            CategoryTableRow::name => CategoryTableRow::name,
        ];
    }

    /**
     * Configures the row shape used by the categories table.
     */
    protected function init(): void
    {
        $this->setRowClass(CategoryTableRow::class);
    }
}
