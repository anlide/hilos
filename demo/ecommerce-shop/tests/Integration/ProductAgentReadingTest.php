<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\ShopFront\ProductReading;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Starts the stock library as its environment does, then the products library beside it, each under its claim and frame.
 *
 * The stock library's environment forgets the loaded memory before its start; the products library
 * starts after that, so the products and their addresses it declares whole are read in once and
 * every product a case adds goes through the library's own actions. The stock library's frame
 * stays current between the cases' writes, and a product write runs under the products library's.
 */
abstract class ProductAgentEnvironment extends StockLibraryEnvironment
{
    private ?ProductsLibraryAgent $products = null;

    /** Claims the products for their library beside the stock library. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        $this->products = new ProductsLibraryAgent();
        OwnershipDeclaration::claimAll($this->products);
    }

    /** Starts the stock library on an empty catalog, then the products library under its own frame. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->asProductsLibrary(fn () => $this->products?->onStart());
    }

    /** Releases the products library's claim, then the stock library's environment. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->products !== null) {
            TruthSourceRegistry::unregisterAgent($this->products->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->products->getId()));
            $this->products = null;
        }
        parent::releaseIntegrationEnvironment();
    }

    /**
     * Runs a product write the way the products library's process runs it.
     *
     * @template T
     * @param callable(): T $write The write
     * @return T What the write returns
     */
    protected function asProductsLibrary(callable $write): mixed
    {
        self::assertNotNull($this->products);

        return ExecutionContext::run(new ExecutionFrame(agentId: $this->products->getId()), $write);
    }
}

/**
 * The agent of a product page address reads the product and its stock anew on every call, through the real shop schema.
 *
 * Categories, pictures and warehouses are rows written straight into their tables past their
 * libraries, before each case's first action; products, their edits and their stock are written
 * by the libraries' own actions. The agent is constructed and not started: its reading is the ORM
 * alone. Names carry a random tail, so an address of the case is the case's own.
 */
#[IntegrationTest]
final class ProductAgentReadingTest extends ProductAgentEnvironment
{
    /** The current address reads its product, with the units summed over the two warehouses that keep it. */
    public function testTheCurrentAddressReadsItsProductAndTheUnitsOfEveryWarehouse(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $id = $this->createProduct('Tulip bulbs ' . RandomHelper::hex(8), ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($id, $kyiv, 12);
        Hilos::$db->productWarehouseStocks->actions->setUnits($id, $lviv, 5);
        $slug = Hilos::$db->products[$id]->slug;

        $reading = self::readingOf($slug);

        self::assertSame($id, $reading->product->id);
        self::assertSame($slug, $reading->product->slug);
        self::assertSame(17, $reading->units);
    }

    /** The agent keeps no picture of what it read: an edit by the library and new units show on the next reading. */
    public function testAnEditAndNewUnitsShowOnTheNextReading(): void
    {
        $kyiv = self::warehouse();
        $name = 'Tulip bulbs ' . RandomHelper::hex(8);
        $id = $this->createProduct($name, ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($id, $kyiv, 12);
        $agent = new ProductAgent(Hilos::$db->products[$id]->slug);
        self::assertSame(12_000, self::read($agent)->product->price);

        $picture = self::pictureFile();
        $category = Hilos::$db->products[$id]->categoryId;
        $this->asProductsLibrary(
            static fn () => Hilos::$db->products[$id]->actions->update($name, $category, ProductKind::STOCK, 15_500, 'Twelve bulbs', $picture),
        );
        Hilos::$db->productWarehouseStocks->actions->setUnits($id, $kyiv, 30);

        $reading = self::read($agent);
        self::assertSame(15_500, $reading->product->price);
        self::assertSame('Twelve bulbs', $reading->product->description);
        self::assertSame($picture, $reading->product->pictureFileId);
        self::assertSame(30, $reading->units);
    }

    /** A former address reads the product under its new name, the new one reads it as current, and a rename back makes the old current again. */
    public function testAFormerAddressReadsTheRenamedProductAndARenameBackMakesItCurrent(): void
    {
        $tail = RandomHelper::hex(8);
        $id = $this->createProduct('Clay pot ' . $tail, ProductKind::UNIQUE);
        $former = Hilos::$db->products[$id]->slug;

        $this->rename($id, 'Glazed pot ' . $tail);
        $current = Hilos::$db->products[$id]->slug;
        self::assertNotSame($former, $current);
        $formerAgent = new ProductAgent($former);
        $currentAgent = new ProductAgent($current);

        $formerReading = self::read($formerAgent);
        self::assertSame($id, $formerReading->product->id);
        self::assertSame($current, $formerReading->product->slug);
        $currentReading = self::read($currentAgent);
        self::assertSame($id, $currentReading->product->id);
        self::assertSame($current, $currentReading->product->slug);

        $this->rename($id, 'Clay pot ' . $tail);
        self::assertSame($former, self::read($formerAgent)->product->slug);
        self::assertSame($former, self::read($currentAgent)->product->slug);
    }

    /** An address no product has had reads nothing, and a product created under it is read by the same agent. */
    public function testAnAddressOfNoProductReadsNothingUntilAProductTakesIt(): void
    {
        $tail = RandomHelper::hex(8);
        $agent = new ProductAgent('morning-meadow-' . $tail);
        self::assertNull($agent->reading());

        $id = $this->createProduct('Morning meadow ' . $tail, ProductKind::UNIQUE);

        self::assertSame($agent->address(), Hilos::$db->products[$id]->slug);
        self::assertSame($id, self::read($agent)->product->id);
    }

    /** A unique piece kept in no warehouse has no units, and placing it gives it one. */
    public function testAUniquePieceInNoWarehouseHasNoUnitsUntilItIsPlaced(): void
    {
        $kyiv = self::warehouse();
        $id = $this->createProduct('Blue vase ' . RandomHelper::hex(8), ProductKind::UNIQUE);
        $agent = new ProductAgent(Hilos::$db->products[$id]->slug);
        self::assertSame(0, self::read($agent)->units);

        Hilos::$db->productWarehouseStocks->actions->place($id, $kyiv);

        self::assertSame(1, self::read($agent)->units);
    }

    /**
     * @param string $name Name of the product, which gives it its address
     * @param ProductKind $kind Kind of the product
     * @return int Id of the product the products library created, in a category of its own
     */
    private function createProduct(string $name, ProductKind $kind): int
    {
        $category = self::category();
        $picture = self::pictureFile();
        $product = $this->asProductsLibrary(
            static fn () => Hilos::$db->products->actions->create($name, $category, $kind, 12_000, 'Product of the case', $picture),
        );

        return $product->id ?? self::fail('The created product has no id');
    }

    /**
     * @param int $id Id of the product
     * @param string $name Its new name, which gives it its new address
     */
    private function rename(int $id, string $name): void
    {
        $this->asProductsLibrary(static function () use ($id, $name): void {
            $product = Hilos::$db->products[$id];
            $product->actions->update($name, $product->categoryId, $product->kind, $product->price, $product->description, null);
        });
    }

    /**
     * @param string $slug Page address
     * @return ProductReading What the agent of that address reads
     */
    private static function readingOf(string $slug): ProductReading
    {
        return self::read(new ProductAgent($slug));
    }

    /**
     * @param ProductAgent $agent Agent of a page address that names a product
     * @return ProductReading What it reads
     */
    private static function read(ProductAgent $agent): ProductReading
    {
        $reading = $agent->reading();
        self::assertNotNull($reading, "The address {$agent->address()} names no product");

        return $reading;
    }
}
