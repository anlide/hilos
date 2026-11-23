<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\View\Item\Category;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CategoryPage;
use Demo\EcommerceShop\Pages\DTO\CategoryPageSubscribeParams;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\Product\CategoryProductsTable;
use Demo\EcommerceShop\Tables\Product\CategoryProductTableRow;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageMovedSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\DbSyncApplicator;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Mounts page dispatch and the connections it reads over the rollback-scoped catalog. */
abstract class CategoryPageEnvironment extends CategoryProductsTableEnvironment
{
    protected const string GUEST_KEY = 'category-guest';
    protected const string VISITOR_KEY = 'category-visitor';
    protected const string RECONNECTED_KEY = 'category-reconnected';
    private const string CONNECTIONS_OWNER = 'category-page-test';

    protected PageSignalRouter $router;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private bool $claimedConnections = false;

    /** Mounts the project browser and ordinary page dispatcher for the categories library. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        parent::prepareIntegrationEnvironment();
        Hilos::initBrowser();
        Hilos::$sr = new EcommerceShopSignalRouter();
        $this->router = new PageSignalRouter(
            new HilosPageFactory(new CategoriesLibraryAgent(), Hilos::class),
            new ActionRouteConfig([]),
        );
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
        $this->claimedConnections = true;
    }

    /** Releases the connections and restores the browser and router used outside the case. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->claimedConnections) {
                ExecutionContext::run(new ExecutionFrame(), static function (): void {
                    foreach ([self::GUEST_KEY, self::VISITOR_KEY, self::RECONNECTED_KEY] as $acceptKey) {
                        Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                    }
                });
                RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
            }
        } finally {
            Hilos::$sr = $this->previousRouter;
            if ($this->previousBrowser === null) {
                Hilos::resetBrowser();
            } else {
                Hilos::initBrowser($this->previousBrowser);
            }
            parent::releaseIntegrationEnvironment();
        }
    }
}

/** The categories library answers the public route with the category and its scoped window together. */
#[IntegrationTest]
final class CategoryPageTest extends CategoryPageEnvironment
{
    private Category $roses;
    private Category $tulips;
    private Category $empty;
    private int $rose;
    private int $tulip;
    private int $tulipBundle;

    public function testColdGuestAndSignedInSubscriptionsCarryTheCategoryAndItsProducts(): void
    {
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            $this->subscribe($acceptKey, $this->roses->slug);
            $this->assertColdWindow($acceptKey, $this->roses, [$this->rose]);
        }

        $this->subscribe(self::GUEST_KEY, $this->empty->slug);
        $this->assertColdWindow(self::GUEST_KEY, $this->empty, []);
    }

    public function testNavigationDiscardsBothTheReportedAndHeldWindowsOfTheOldCategory(): void
    {
        $old = $this->customWindow($this->roses->id);
        $this->subscribe(self::GUEST_KEY, $this->roses->slug, [CategoryProductsTable::TABLE => $old]);
        $this->assertWindow(self::GUEST_KEY, $this->roses, [], $old);

        $this->subscribe(self::GUEST_KEY, $this->tulips->slug, [CategoryProductsTable::TABLE => $old]);
        $this->assertColdWindow(self::GUEST_KEY, $this->tulips, [$this->tulip, $this->tulipBundle]);
    }

    public function testUpdatingTheRouteWithoutAReportResetsTheHeldWindowToTheNewCategory(): void
    {
        $old = $this->customWindow($this->roses->id);
        $this->subscribe(self::GUEST_KEY, $this->roses->slug, [CategoryProductsTable::TABLE => $old]);
        $this->assertWindow(self::GUEST_KEY, $this->roses, [], $old);

        $this->router->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO(
                self::GUEST_KEY,
                CategoryPage::PAGE,
                [CategoryPageSubscribeParams::SLUG => $this->tulips->slug],
            ),
            SignalSource::WORKER,
            CategoryPage::PAGE,
        );
        $this->assertColdWindow(self::GUEST_KEY, $this->tulips, [$this->tulip, $this->tulipBundle]);
    }

    public function testTheSameCategoryRestoresItsReportOrKeepsItsHeldWindow(): void
    {
        $wanted = $this->customWindow($this->tulips->id);
        $this->subscribe(self::GUEST_KEY, $this->tulips->slug, [CategoryProductsTable::TABLE => $wanted]);
        $this->assertWindow(self::GUEST_KEY, $this->tulips, [$this->tulip], $wanted);

        self::assertNull(Hilos::$sr->getTableViewport(self::RECONNECTED_KEY, CategoryProductsTable::TABLE));
        $this->subscribe(self::RECONNECTED_KEY, $this->tulips->slug, [CategoryProductsTable::TABLE => $wanted]);
        $this->assertWindow(self::RECONNECTED_KEY, $this->tulips, [$this->tulip], $wanted);

        $this->subscribe(self::RECONNECTED_KEY, $this->tulips->slug);
        $this->assertWindow(self::RECONNECTED_KEY, $this->tulips, [$this->tulip], $wanted);

        $this->subscribe(
            self::RECONNECTED_KEY,
            $this->tulips->slug,
            [CategoryProductsTable::TABLE => $this->customWindow($this->roses->id)],
        );
        $this->assertWindow(self::RECONNECTED_KEY, $this->tulips, [$this->tulip], $wanted);
    }

    /** Every exact former address goes directly to the current category and its scoped window. */
    public function testFormerCategoryAddressesMoveDirectlyToTheCurrentAddress(): void
    {
        $oldSlugs = ['old-tulips', 'older-tulips'];
        foreach ($oldSlugs as $slug) {
            Database::sql('INSERT INTO category_slug (slug, category_id) VALUES (?, ?)', [$slug, $this->tulips->id]);
        }
        Database::sql('INSERT INTO category_slug (slug, category_id) VALUES (?, NULL)', ['retired-tulips']);

        foreach ($oldSlugs as $slug) {
            $this->subscribe(self::GUEST_KEY, $slug);
            $this->assertMove($slug, $this->tulips->slug);
            self::assertNull(Hilos::$sr->pageResource(self::GUEST_KEY));
            self::assertNull(Hilos::$sr->getTableViewport(self::GUEST_KEY, CategoryProductsTable::TABLE));
        }

        foreach (['retired-tulips', $oldSlugs[0] . ' '] as $slug) {
            $this->subscribe(self::GUEST_KEY, $slug);
            $refusal = $this->takePageFrame(self::GUEST_KEY, SignalConstants::SUBSCRIPTION_PAGE_ERROR);
            self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal);
            self::assertSame(HttpConstants::HTTP_NOT_FOUND, $refusal->httpCode);
        }

        $this->subscribe(self::GUEST_KEY, $this->tulips->slug);
        $this->assertColdWindow(self::GUEST_KEY, $this->tulips, [$this->tulip, $this->tulipBundle]);
        self::assertSame((string) $this->tulips->id, Hilos::$sr->pageResource(self::GUEST_KEY)?->idString);
    }

    /** A live rename moves just the affected category and its previous window can be reported again. */
    public function testLiveRenameMovesOnlyTheAffectedCategory(): void
    {
        $resender = $this->createStub(PageResender::class);
        $resender->method('moveRenamedResource')->willReturnCallback($this->router->moveRenamedResource(...));
        Hilos::$browser->bindPageResender($resender);

        $oldSlug = $this->tulips->slug;
        $newSlug = 'spring-tulips';
        $window = $this->customWindow($this->tulips->id);
        $report = [CategoryProductsTable::TABLE => $window];
        $this->subscribe(self::GUEST_KEY, $oldSlug, $report);
        $this->assertWindow(self::GUEST_KEY, $this->tulips, [$this->tulip], $window);
        $this->subscribe(self::VISITOR_KEY, $this->roses->slug);
        $this->assertColdWindow(self::VISITOR_KEY, $this->roses, [$this->rose]);

        Database::sql('UPDATE category SET slug = ? WHERE id = ?', [$newSlug, $this->tulips->id]);
        $collection = EcommerceShopDbContext::categories;
        $key = (string) $this->tulips->id;
        $row = [EntityCategory::slug => $newSlug];
        $previous = [EntityCategory::slug => $oldSlug];
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData($collection, $key, $row, previous: $previous));
        Hilos::$browser->record(SourceChange::dbUpdated($collection, $key, $row, previous: $previous));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $this->assertMove($oldSlug, $newSlug);
        self::assertNull(Hilos::$sr->pageResource(self::GUEST_KEY));
        self::assertSame((string) $this->roses->id, Hilos::$sr->pageResource(self::VISITOR_KEY)?->idString);

        $this->subscribe(self::GUEST_KEY, $newSlug, $report);
        self::assertSame($newSlug, $this->tulips->slug);
        $this->assertWindow(self::GUEST_KEY, $this->tulips, [$this->tulip], $window);
        self::assertSame($key, Hilos::$sr->pageResource(self::GUEST_KEY)?->idString);
    }

    public function testUnknownAndMissingSlugsReceiveOneServerRefusal(): void
    {
        foreach (['no-such-category', $this->roses->slug . ' '] as $slug) {
            $this->subscribe(self::GUEST_KEY, $slug);
            $refusal = $this->takePageFrame(self::GUEST_KEY, SignalConstants::SUBSCRIPTION_PAGE_ERROR);
            self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal);
            self::assertSame(CategoryPage::PAGE, $refusal->page);
            self::assertSame(HttpConstants::HTTP_NOT_FOUND, $refusal->httpCode);
            self::assertSame('not_found', $refusal->errorCode);
            self::assertSame('The page you are looking for does not exist.', $refusal->message);
        }

        foreach ([null, ''] as $slug) {
            $this->subscribe(self::GUEST_KEY, $slug);
            $refusal = $this->takePageFrame(self::GUEST_KEY, SignalConstants::SUBSCRIPTION_PAGE_ERROR);
            self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal);
            self::assertSame(HttpConstants::HTTP_BAD_REQUEST, $refusal->httpCode);
        }
    }

    /** Creates two populated categories, an empty one and the connections used to open them. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $roses = self::category('Roses');
        $tulips = self::category('Tulips');
        $empty = self::category('Lilies');
        $this->roses = Hilos::$db->categories[$roses];
        $this->tulips = Hilos::$db->categories[$tulips];
        $this->empty = Hilos::$db->categories[$empty];
        $this->rose = self::productIn($this->roses->id, ProductKind::STOCK, 'Rose', 100, 1795132800123);
        $this->tulip = self::productIn($this->tulips->id, ProductKind::STOCK, 'Tulip', 200, 1795132800124);
        $this->tulipBundle = self::productIn($this->tulips->id, ProductKind::STOCK, 'Tulip bundle', 400, 1795132800125);
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Category visitor']);
        $visitorId = Database::lastInsertId();
        ExecutionContext::run(new ExecutionFrame(), static function () use ($visitorId): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
            Hilos::$rt->connections->actions->register(self::RECONNECTED_KEY, null);
        });
    }

    /**
     * @param string $acceptKey Connection opening the page
     * @param ?string $slug Raw category route param, or null when missing
     * @param array<string, TableWindowDescriptorDTO> $reports Windows reported by the connection
     */
    private function subscribe(string $acceptKey, ?string $slug, array $reports = []): void
    {
        $params = $slug === null ? [] : [CategoryPageSubscribeParams::SLUG => $slug];
        $request = new WebSocketPageSubscribeSignalDTO($acceptKey, CategoryPage::PAGE, $params, $reports);
        $this->router->dispatchPageSubscribe($request, SignalSource::WORKER, CategoryPage::PAGE);
        Hilos::$sr->subscribeToPage(CategoryPage::PAGE, $request);
    }

    /**
     * @param string $acceptKey Connection awaiting the one answer
     * @param string $name Expected signal name
     * @return PageResponseSignalData|PageSubscriptionErrorSignalData|PageMovedSignalData The only page answer
     */
    private function takePageFrame(string $acceptKey, string $name): PageResponseSignalData|PageSubscriptionErrorSignalData|PageMovedSignalData
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->data instanceof WebSocketSignalData
                && ($signal->data->data instanceof PageResponseSignalData
                    || $signal->data->data instanceof PageSubscriptionErrorSignalData
                    || $signal->data->data instanceof PageMovedSignalData)
            ) {
                $frames[] = $signal;
            }
        }
        self::assertCount(1, $frames);
        self::assertSame($acceptKey, $frames[0]->data->targetAcceptKey);
        self::assertSame($name, $frames[0]->signalName->getName());

        return $frames[0]->data->data;
    }

    /**
     * @param string $oldSlug Requested or held category address
     * @param string $newSlug Current category address
     */
    private function assertMove(string $oldSlug, string $newSlug): void
    {
        $move = $this->takePageFrame(self::GUEST_KEY, SignalConstants::PAGE_MOVED);
        self::assertInstanceOf(PageMovedSignalData::class, $move);
        self::assertSame(CategoryPage::PAGE, $move->page);
        self::assertSame([CategoryPageSubscribeParams::SLUG => $oldSlug], $move->params);
        self::assertSame(CategoryPage::PAGE, $move->targetPage);
        self::assertSame([CategoryPageSubscribeParams::SLUG => $newSlug], $move->targetParams);
    }

    /**
     * @param int $categoryId Route-selected category filter
     * @return TableWindowDescriptorDTO Window with a kind filter, descending price and the second row selected
     */
    private function customWindow(int $categoryId): TableWindowDescriptorDTO
    {
        return new TableWindowDescriptorDTO(
            filter: [
                CategoryProductsTable::FILTER_CATEGORY => $categoryId,
                CategoryProductsTable::FILTER_KIND => ProductKind::STOCK->value,
            ],
            sort: TableSortOrderDTO::of(new TableSortDTO(CategoryProductTableRow::price, TableConstants::ORDER_DESC)),
            limit: 1,
            pageIndex: 1,
            facets: [CategoryProductsTable::FILTER_KIND => [ProductKind::STOCK->value]],
            rendered: [CategoryProductTableRow::name, CategoryProductTableRow::price],
        );
    }

    /**
     * @param string $acceptKey Connection awaiting the category and its first window
     * @param Category $category Category named by the route
     * @param list<int> $rowKeys Expected product ids in price order
     */
    private function assertColdWindow(string $acceptKey, Category $category, array $rowKeys): void
    {
        $table = Hilos::$table->categoryProducts;
        $this->assertWindow($acceptKey, $category, $rowKeys, new TableWindowDescriptorDTO(
            filter: [CategoryProductsTable::FILTER_CATEGORY => $category->id],
            sort: $table->defaultSort(),
            limit: $table->windowSize(),
        ));
    }

    /**
     * @param string $acceptKey Connection awaiting the category and its products
     * @param Category $category Category named by the route
     * @param list<int> $rowKeys Expected product ids in this window
     * @param TableWindowDescriptorDTO $expected Expected filters, position and facet request
     */
    private function assertWindow(string $acceptKey, Category $category, array $rowKeys, TableWindowDescriptorDTO $expected): void
    {
        $response = $this->takePageFrame($acceptKey, SignalTypeConstants::PAGE_RESPONSE);
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(CategoryPage::PAGE, $response->pageKey);
        $payload = $response->payload->toArray();
        self::assertSame([
            'id' => $category->id,
            'slug' => $category->slug,
            'name' => $category->name,
            'description' => 'Flowers of the case',
        ], $payload[PagePayload::data]['category']);
        self::assertSame([CategoryProductsTable::TABLE], array_keys($payload[PagePayload::windows]));
        $window = $payload[PagePayload::windows][CategoryProductsTable::TABLE];
        self::assertSame($expected->filter, (array) $window[TableWindowDescriptorDTO::FILTER]);
        self::assertSame($expected->sort?->toArray(), $window[TableWindowDescriptorDTO::SORT]);
        self::assertSame($expected->limit, $window[TableWindowSignalData::limit]);
        self::assertSame($rowKeys, array_column($window[PagePayload::rows], PagePayload::rowKey));

        $held = Hilos::$sr->getTableViewport($acceptKey, CategoryProductsTable::TABLE);
        self::assertInstanceOf(TableViewportSubscription::class, $held);
        self::assertSame($expected->pageIndex, $held->pageIndex);
        self::assertSame($expected->anchor, $held->anchor);
        self::assertSame($expected->rendered, $held->rendered);
        self::assertSame($expected->facets, Hilos::$sr->getTableFacets($acceptKey, CategoryProductsTable::TABLE));
    }
}
