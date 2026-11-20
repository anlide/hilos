<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductsPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\Product\ProductTableRow;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\DbSyncApplicator;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Mounts the shop's browser and its own signal router over the products environment, turns the admin
 * view mode on, and claims the connections register the page gate reads a subscriber's identity from.
 *
 * The runtime writes run outside any agent, as the daemon's do: the environment beneath runs under the
 * stock library's frame, which owns neither the connections nor the view mode.
 */
abstract class AdminProductsPageEnvironment extends ProductsTableEnvironment
{
    private const string TEST_AGENT = 'admin-products-page-test';

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;

    /** Mounts the browser, router, connections claim and view mode over the libraries beneath. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
        Hilos::initBrowser();
        Hilos::$sr = new EcommerceShopSignalRouter();
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->claimed = true;
        self::unattended(static function (): void {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        });
    }

    /**
     * Turns the view mode off and forgets the connections if they were claimed, restores the router and
     * the browser, then releases beneath, in every outcome of the preparation.
     */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->claimed) {
                $this->claimed = false;
                self::unattended(static function (): void {
                    Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
                    Hilos::$rt->connections->actions->clear();
                });
                RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
                RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
            }
            Hilos::$sr = $this->previousRouter;
            Hilos::initBrowser();
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * Runs a runtime write outside any agent, the way the daemon writes.
     *
     * @param callable(): void $write The write
     */
    protected static function unattended(callable $write): void
    {
        ExecutionContext::run(new ExecutionFrame(), $write);
    }
}

/** The products page exposes its table and live category options to viewers, with the admin gate still closed when view mode is off. */
#[IntegrationTest]
final class AdminProductsPageTest extends AdminProductsPageEnvironment
{
    private const string GUEST_KEY = 'admin-products-guest';
    private const string VISITOR_KEY = 'admin-products-visitor';
    private const string ADMIN_KEY = 'admin-products-admin';

    private int $categoryId;
    private int $categoryPosition;
    private string $categoryName;
    private int $pictureFileId;
    private int $productId;
    private string $productName;

    /** One stock product without stock, and a guest, a person without admin rights and an administrator connected. */
    protected function setUpInTransaction(): void
    {
        $this->categoryId = self::category();
        $category = Database::sql('SELECT * FROM category WHERE id = ?', [$this->categoryId])->rows()[0];
        $this->categoryName = $category['name'];
        $this->categoryPosition = (int)$category['position'];
        $this->productName = 'Product ' . RandomHelper::hex(8);
        $this->productId = self::productIn($this->categoryId, ProductKind::STOCK, $this->productName, 41000, 1795132800123);
        $this->pictureFileId = (int)Database::sql('SELECT picture_file_id FROM product WHERE id = ?', [$this->productId])
            ->field('picture_file_id');
        self::holdCatalog();
        $visitorId = self::person('Products visitor', false);
        $adminId = self::person('Products admin', true);
        self::unattended(static function () use ($visitorId, $adminId): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
            Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
        });
    }

    /** A viewer receives the product and category options together in the first answer, with every field shown. */
    public function testAViewerSeesTheProductAndCategoriesWithEveryFieldShown(): void
    {
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            $payload = $this->subscribe($acceptKey)[0][PageResponseSignalData::payload];
            $slot = self::slotFor(
                $payload[PagePayload::windows][EcommerceShopTableContext::products][TableWindowSignalData::rows],
                PagePayload::rowKey,
                $this->productId,
                'product',
            );
            self::assertSame([
                ProductTableRow::id => $this->productId,
                ProductTableRow::name => $this->productName,
                ProductTableRow::pictureUrl => HilosFiles::downloadPath($this->pictureFileId, CatalogPictureUploadTarget::VARIANT_XS),
                ProductTableRow::categoryId => $this->categoryId,
                ProductTableRow::categoryName => $this->categoryName,
                ProductTableRow::kind => 'stock',
                ProductTableRow::price => 41000,
                ProductTableRow::units => 0,
                ProductTableRow::warehouseName => null,
                ProductTableRow::availability => 'out_of_stock',
                ProductTableRow::changedAt => 1795132800123,
            ], $slot);
            $category = self::slotFor(
                $payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES][PagePayload::items],
                PagePayload::itemKey,
                $this->categoryId,
                EcommerceShopDbContext::categories,
            );
            self::assertSame([
                ObjectCategory::id => $this->categoryId,
                ObjectCategory::name => $this->categoryName,
                ObjectCategory::position => $this->categoryPosition,
            ], $category);
            foreach ([$slot, $category] as $fields) {
                foreach ($fields as $field => $value) {
                    self::assertFalse(HiddenValue::isMark($value), "field {$field} is a mark for {$acceptKey}");
                }
            }
        }
    }

    /** An administrator is sent the page without a single mark. */
    public function testAnAdminIsSentThePageWithoutASingleMark(): void
    {
        $payloads = $this->subscribe(self::ADMIN_KEY);

        self::assertArrayHasKey(EcommerceShopTableContext::products, $payloads[0][PageResponseSignalData::payload][PagePayload::windows]);
        self::assertArrayHasKey(
            EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES,
            $payloads[0][PageResponseSignalData::payload][PagePayload::lists],
        );
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($payloads, JSON_THROW_ON_ERROR));
    }

    /** With the view mode off the page is closed to a person without admin rights. */
    public function testANonAdminIsRefusedWhenTheViewModeIsOff(): void
    {
        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AdminProductsPage::class, self::VISITOR_KEY);
    }

    /** A category rename reaches the subscribed viewer as a list item update. */
    public function testACategoryRenameReachesTheSubscribedViewer(): void
    {
        $this->subscribe(self::GUEST_KEY);
        Database::sql('UPDATE category SET name = ? WHERE id = ?', ['Renamed category', $this->categoryId]);
        $row = Database::sql('SELECT * FROM category WHERE id = ?', [$this->categoryId])->rows()[0];
        // A worker applies DB sync to held rows before handing its fact to the browser.
        self::assertTrue(DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::categories,
            (string)$this->categoryId,
            $row,
        ))->accepted);
        Hilos::$browser->record(SourceChange::dbUpdated(EcommerceShopDbContext::categories, (string)$this->categoryId, $row));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $updates = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE
                || !$signal->data instanceof WebSocketSignalData
                || $signal->data->targetAcceptKey !== self::GUEST_KEY
                || !$signal->data->data instanceof PageResponseSignalData
            ) {
                continue;
            }
            self::assertSame(AdminProductsPage::PAGE, $signal->data->data->pageKey);
            $payload = $signal->data->data->payload->toArray();
            if (isset($payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES])) {
                $updates[] = $payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES];
            }
        }
        self::assertCount(1, $updates);
        self::assertSame([
            ObjectCategory::id => $this->categoryId,
            ObjectCategory::name => 'Renamed category',
            ObjectCategory::position => $this->categoryPosition,
        ], self::slotFor($updates[0][PagePayload::items], PagePayload::itemKey, $this->categoryId, EcommerceShopDbContext::categories));
    }

    /**
     * Writes a person past the users library and drops a verdict an earlier case of this process may have
     * remembered for the same id.
     *
     * @param string $name Name of the person
     * @param bool $admin Whether the person is an administrator
     * @return int Id of the new person
     */
    private static function person(string $name, bool $admin): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, ?, 0)', [$name, (int)$admin]);
        $userId = Database::lastInsertId();
        AccountStandingResolver::forget($userId);

        return $userId;
    }

    /**
     * Subscribes one connection with an open products window and reads the one page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @return list<array<string, mixed>> Wire arrays of the page answers, exactly one
     */
    private function subscribe(string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $windows = [EcommerceShopTableContext::products => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
        Hilos::$sr->subscribeToPage(
            AdminProductsPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminProductsPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new AdminProductsPage(new ProductsLibraryAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });

        $payloads = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $payloads[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $payloads);

        return $payloads;
    }

    /**
     * @param list<array<string, mixed>> $items Table rows or list items
     * @param string $keyField Identity field of the row or item
     * @param int $id Expected identity
     * @param string $slot Source slot to read
     * @return array<string, mixed> Fields of the matching slot
     */
    private static function slotFor(array $items, string $keyField, int $id, string $slot): array
    {
        foreach ($items as $item) {
            if ((string)$item[$keyField] === (string)$id) {
                return $item[PagePayload::slots][$slot];
            }
        }
        self::fail("No {$slot} item for {$id}");
    }
}
