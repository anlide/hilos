<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Database\Actions\ProductFields;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\DTO\ProductStockTotalsCollection;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\PageHandoffIdentity;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/** A worker with identified connections over a rollback-scoped catalog. */
abstract class CartPageEnvironment extends StockLibraryEnvironment
{
    protected const string FIRST = 'cart-page-first';
    protected const string SECOND = 'cart-page-second';
    protected const string ACCOUNT = 'cart-page-account';
    protected const string EMPTY = 'cart-page-empty';
    private const string CONNECTIONS_OWNER = 'cart-page-test';

    private EcommerceShopWorkerManager $worker;
    private AgentManager $agents;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private bool $claimedConnections = false;

    /** Sets up a worker with cold stock and cart collections, like a worker receiving the subscription. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetHoldingForTest(
            EcommerceShopDbContext::productWarehouseStocks,
            EcommerceShopDbContext::sessionProductCarts,
            EcommerceShopDbContext::userProductCarts,
        );
        Hilos::initBrowser();
        $this->worker = new EcommerceShopWorkerManager(1);
        $this->agents = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($this->worker);
        Hilos::$browser->bindPageResender($this->worker);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
        $this->claimedConnections = true;
    }

    /** Empties the fixtures without starting the stock holder in the reader's context. */
    protected function setUpInTransaction(): void
    {
        self::emptyCatalog();
    }

    /** Releases the test connections and restores the facades and claims. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->claimedConnections) {
                ExecutionContext::run(new ExecutionFrame(), static function (): void {
                    foreach ([self::FIRST, self::SECOND, self::ACCOUNT, self::EMPTY] as $acceptKey) {
                        Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                        SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
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

    /**
     * @param AgentInterface $agent Addressed owner
     * @param string $type Page signal type
     * @param SignalDataInterface $payload Page request
     * @param string $source Logical source of the request
     * @param array<string, int|string> $meta Existing internal handoff metadata
     */
    protected function deliver(
        AgentInterface $agent,
        string $type,
        SignalDataInterface $payload,
        string $source = SignalSource::DAEMON,
        array $meta = [],
    ): void {
        $this->agents->addAgent($agent->getId(), $agent);
        $message = new DaemonAgentMessageDTO($agent->getId(), new SignalDTO(
            new SignalSource($source),
            new SignalType($type),
            new SignalName(CartPage::PAGE),
            $payload,
            $meta,
        ));
        $this->deliverCartWorkerMessage($message);
    }

    /** @param WorkerDTO $message Frame addressed to the worker, even when its agent is absent */
    protected function deliverCartWorkerMessage(WorkerDTO $message): void
    {
        ExecutionContext::run(new ExecutionFrame(), fn () => $this->worker->handleDaemonMessage($message));
    }

    /** @param GuestCartsLibraryAgent $guest Agent asked for the cart after the connection row is gone */
    protected function dispatchReassessmentWithoutConnection(GuestCartsLibraryAgent $guest): void
    {
        $router = new ReflectionMethod(WorkerManager::class, 'getPageSignalRouter')->invoke($this->worker, $guest->getId(), $guest);
        ExecutionContext::run(new ExecutionFrame(), static fn () => $router->dispatchPageAccessReassess(
            new WebSocketPageSubscribeSignalDTO(self::EMPTY, CartPage::PAGE), SignalSource::DAEMON, CartPage::PAGE,
        ));
    }

    /** @param RtSyncUpdatedSignalData $fact Connection update delivered by the worker's RT stream */
    protected function applyCartConnectionFact(RtSyncUpdatedSignalData $fact): void
    {
        $apply = new ReflectionMethod(WorkerManager::class, 'appliedStreamedRtSync');
        $operation = RtSyncStreamOperation::fromSignalData($fact, 1);
        ExecutionContext::run(new ExecutionFrame(), function () use ($apply, $fact, $operation): void {
            RtWorkDriver::runSynchronously($apply->invoke($this->worker, $operation, static function (bool $applied): void {
                self::assertTrue($applied);
            }));
            // Keep the enclosing integration transaction's execution frames on the stack.
            $ready = new ReflectionProperty(WorkerManager::class, 'readyPageHandoffs')->getValue($this->worker);
            self::assertInstanceOf(DaemonAgentMessageDTO::class, $ready[$fact->stateId] ?? null);
            $this->worker->handleDaemonMessage($ready[$fact->stateId]);
        });
    }

    /**
     * @param WorkerDTO ...$messages DB facts from the daemon to apply before the browser flush
     * @return list<ContainedFailure> Fan-out failures for this flush
     */
    protected function flushCartFacts(WorkerDTO ...$messages): array
    {
        return ExecutionContext::run(new ExecutionFrame(), function () use ($messages): array {
            foreach ($messages as $message) {
                $this->worker->handleDaemonMessage($message);
            }

            return Hilos::$browser->flushToSignalRouter();
        });
    }
}

/** The cart's first response, live facts and handoff through the production worker. */
#[IntegrationTest]
final class CartPageTest extends CartPageEnvironment
{
    /** Two guest sessions and an account get distinct carts, and a settled empty session gets a complete answer. */
    public function testEachOwnerGetsItsOwnCartAndAnEmptyCartStillAnswers(): void
    {
        $firstProduct = self::product(ProductKind::STOCK);
        $secondProduct = self::product(ProductKind::STOCK);
        $userId = self::person();
        self::guestLine(101, $secondProduct, 2);
        self::guestLine(101, $firstProduct, 3);
        self::guestLine(102, $firstProduct, 1);
        self::accountLine($userId, $secondProduct, 5);
        self::connect(self::FIRST, null, 101);
        self::connect(self::SECOND, null, 102);
        self::connect(self::ACCOUNT, $userId, 103);
        self::connect(self::EMPTY, null, 104);

        $guest = new GuestCartsLibraryAgent();
        $this->subscribe($guest, self::FIRST);
        $first = $this->cart(self::FIRST);
        self::assertNull($first['userId']);
        self::assertSame([$secondProduct, $firstProduct], array_column($first['lines'], 'productId'));
        self::assertSame([2, 3], array_column($first['lines'], 'quantity'));
        $this->subscribe($guest, self::SECOND);
        $second = $this->cart(self::SECOND);
        self::assertNull($second['userId']);
        self::assertSame([$firstProduct], array_column($second['lines'], 'productId'));
        self::assertSame([1], array_column($second['lines'], 'quantity'));
        $this->subscribe(new UserAgent((string)$userId), self::ACCOUNT);
        $account = $this->cart(self::ACCOUNT);
        self::assertSame($userId, $account['userId']);
        self::assertSame([$secondProduct], array_column($account['lines'], 'productId'));
        self::assertSame([5], array_column($account['lines'], 'quantity'));

        $this->subscribe($guest, self::EMPTY);
        self::assertSame(['userId' => null, 'lines' => [], 'itemsTotal' => '0'], $this->cart(self::EMPTY));
    }

    /** A fallback cannot guess the owner while identity is pending, missing a session, or already tied to an account. */
    public function testUnconfirmedConnectionsAndMismatchedAgentsAreRefused(): void
    {
        $guest = new GuestCartsLibraryAgent();
        $this->subscribe($guest, self::FIRST);
        self::assertSame([], $this->takePageFrames());
        $this->deliver(
            $guest,
            SignalTypeConstants::PAGE_ACCESS_REASSESS,
            new WebSocketPageSubscribeSignalDTO(self::EMPTY, CartPage::PAGE),
        );
        self::assertSame([], $this->takePageFrames());
        $this->dispatchReassessmentWithoutConnection($guest);
        $this->assertRefusal(self::EMPTY);

        self::connect(self::SECOND, null, null);
        $this->subscribe($guest, self::SECOND);
        $this->assertRefusal(self::SECOND);

        $userId = self::person();
        self::connect(self::ACCOUNT, $userId, 103);
        self::guestLine(103, self::product(ProductKind::STOCK), 1);
        $this->subscribe($guest, self::ACCOUNT);
        $this->assertRefusal(self::ACCOUNT);

        $this->subscribe(new UserAgent((string)self::person()), self::ACCOUNT);
        $this->assertRefusal(self::ACCOUNT);
    }

    /** The same page key and connection get a whole cart from each new owner after the runtime identity changes. */
    public function testIdentityReassessmentReplacesTheCartOnTheSamePage(): void
    {
        $guestProduct = self::product(ProductKind::STOCK);
        $accountProduct = self::product(ProductKind::STOCK);
        $userId = self::person();
        self::guestLine(101, $guestProduct, 1);
        self::accountLine($userId, $accountProduct, 2);
        self::connect(self::FIRST, null, 101);
        $guest = new GuestCartsLibraryAgent();
        $account = new UserAgent((string)$userId);
        $this->subscribe($guest, self::FIRST);
        self::assertSame([$guestProduct], array_column($this->cart(self::FIRST)['lines'], 'productId'));

        self::bindFirstUser($userId);
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($guestProduct, 8000), self::productPriceFact($accountProduct, 9000)));
        self::assertSame([], $this->takePageFrames());
        $this->handoff($guest, $account);
        $cart = $this->cart(self::FIRST);
        self::assertSame($userId, $cart['userId']);
        self::assertSame([$accountProduct], array_column($cart['lines'], 'productId'));
        self::assertSame((string)$userId, Hilos::$sr->pageSubscription(self::FIRST)?->agentIndex);

        self::assertSame([], $this->flushCartFacts(self::productPriceFact($guestProduct, 10_000)));
        self::assertSame([], $this->takePageFrames());
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($accountProduct, 11_000)));
        self::assertSame(11_000, $this->cart(self::FIRST)['lines'][0]['price']);

        self::bindFirstUser(null);
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($accountProduct, 12_000)));
        self::assertSame([], $this->takePageFrames());
        $this->handoff($account, $guest);
        $cart = $this->cart(self::FIRST);
        self::assertNull($cart['userId']);
        self::assertSame([$guestProduct], array_column($cart['lines'], 'productId'));
        self::assertSame(AgentType::GUEST_CARTS_LIBRARY, Hilos::$sr->pageSubscription(self::FIRST)?->agentType);

        self::assertSame([], $this->flushCartFacts(self::productPriceFact($accountProduct, 13_000)));
        self::assertSame([], $this->takePageFrames());
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($guestProduct, 14_000)));
        self::assertSame(14_000, $this->cart(self::FIRST)['lines'][0]['price']);
    }

    /** @return iterable<string, array{bool, string}> Owner kind and its membership collection */
    public static function retainedCartOwners(): iterable
    {
        yield 'account' => [true, EcommerceShopDbContext::userProductCarts];
        yield 'guest' => [false, EcommerceShopDbContext::sessionProductCarts];
    }

    /**
     * @param bool $registered Whether the cart belongs to an account
     * @param string $membership Cart line collection read by the storefront observer
     */
    #[DataProvider('retainedCartOwners')]
    public function testRetainedCartCanMatchProductFactsAfterItsOwnerStops(bool $registered, string $membership): void
    {
        $product = self::product(ProductKind::STOCK);
        $otherProduct = self::product(ProductKind::STOCK);
        $sessionId = 101;
        $userId = $registered ? self::person() : null;
        if ($userId === null) {
            self::guestLine($sessionId, $product, 1);
            self::guestLine(102, $otherProduct, 1);
            $owner = new GuestCartsLibraryAgent();
        } else {
            self::accountLine($userId, $product, 1);
            self::accountLine(self::person(), $otherProduct, 1);
            $owner = new UserAgent((string) $userId);
        }
        self::connect(self::FIRST, $userId, $sessionId);

        $ownerReader = SourceConsumer::agent($owner->getId());
        $neighbor = SourceConsumer::feature('cart-test-catalog-reader');
        SourceInterestRegistry::register(SourceChange::KIND_DB, EcommerceShopDbContext::products, $neighbor);
        foreach ([EcommerceShopDbContext::products, EcommerceShopDbContext::productWarehouseStocks, $membership] as $collection) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $collection, $ownerReader);
        }

        $deliveryId = self::FIRST . ':1';
        $consumer = SourceConsumer::page(self::FIRST);
        try {
            $this->subscribeTrackedCart($owner, $deliveryId);
            self::assertSame([$product], array_column($this->cart(self::FIRST)['lines'], 'productId'));

            foreach (SourceInterestRegistry::collections(SourceChange::KIND_DB) as $collection) {
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collection);
            }
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, EcommerceShopRtContext::connections);
            SourceInterestRegistry::readsWhatIsDelivered();
            $this->deliverCartWorkerMessage(new AgentStopDTO($owner->getId(), AgentStopDTO::KEEP_RESTART_MIRRORS));
            self::assertTrue(Hilos::$sr->pageSubscription(self::FIRST)?->retainedForRestart);
            self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer($ownerReader, SourceChange::KIND_DB));
            self::assertNotNull(Hilos::$db->products[$product]);

            self::assertSame([], $this->flushCartFacts(self::productPriceFact($otherProduct, 1400)));
            self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
            self::assertSame([], $this->takePageFrames());
            self::assertSame([], $this->flushCartFacts(self::productPriceFact($product, 1500)));
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
            self::assertSame([], $this->takePageFrames());
            self::assertSame([$consumer], SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, $membership));
            self::assertEqualsCanonicalizing([
                EcommerceShopDbContext::products,
                EcommerceShopDbContext::productWarehouseStocks,
                EcommerceShopDbContext::userProductCarts,
                EcommerceShopDbContext::sessionProductCarts,
            ], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_DB));

            $this->deliverCartWorkerMessage(new DaemonAgentMessageDTO($owner->getId(), new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                new SignalName(CartPage::PAGE),
                new WebSocketPageUnsubscribeSignalDTO(self::FIRST),
                [WebSocketPageSubscribeSignalDTO::DELIVERY_ID => $deliveryId],
            )));
            self::assertNull(Hilos::$sr->pageSubscription(self::FIRST));
            self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_DB));
            self::assertSame([$neighbor], SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, EcommerceShopDbContext::products));
            self::assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, $membership));
            self::assertSame(1500, Hilos::$db->products[$product]->price);
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            SourceInterestRegistry::releaseConsumer($ownerReader);
            SourceInterestRegistry::releaseConsumer($neighbor);
        }
    }

    /** A loaded guest row can name the wrong session, even though the user and serving agent agree. */
    public function testGuestHandoffWaitsForItsSessionBeforeAnsweringAnEmptyCart(): void
    {
        self::connect(self::FIRST, null, 101);
        self::guestLine(101, self::product(ProductKind::STOCK), 1);
        $guest = new GuestCartsLibraryAgent();
        $expected = new PageHandoffIdentity(EcommerceShopRtContext::connections, null, 102);
        $this->deliver(
            $guest, SignalTypeConstants::PAGE_ACCESS_REASSESS,
            new WebSocketPageSubscribeSignalDTO(self::FIRST, CartPage::PAGE),
            meta: $expected->toMeta(),
        );
        self::assertSame([], $this->takePageFrames());
        self::assertNull(Hilos::$sr->pageSubscription(self::FIRST));

        $this->applyCartConnectionFact(new RtSyncUpdatedSignalData(
            EcommerceShopRtContext::connections, self::FIRST, [StateHilosSessionConnection::sessionId => 102],
        ));
        self::assertSame(['userId' => null, 'lines' => [], 'itemsTotal' => '0'], $this->cart(self::FIRST));
    }

    /** Grouped stock, price and quantity facts refresh only their owner's cart, with final values. */
    public function testLiveCartFactsAreAddressedAndGrouped(): void
    {
        $guestProduct = self::product(ProductKind::STOCK);
        $otherProduct = self::product(ProductKind::STOCK);
        $accountProduct = self::product(ProductKind::STOCK);
        $unrelatedProduct = self::product(ProductKind::STOCK);
        $userId = self::person();
        $guestLine = self::guestLine(101, $guestProduct, 2);
        self::guestLine(102, $otherProduct, 1);
        $unrelatedLine = self::guestLine(104, $guestProduct, 1);
        $accountLine = self::accountLine($userId, $accountProduct, 2);
        $warehouse = self::warehouse();
        self::stock($guestProduct, $warehouse, 5);
        $guestStock = Database::lastInsertId();
        self::stock($accountProduct, $warehouse, 4);
        $accountStock = Database::lastInsertId();

        $guest = new GuestCartsLibraryAgent();
        self::connect(self::FIRST, null, 101);
        $this->subscribe($guest, self::FIRST);
        self::assertSame('24000', $this->cart(self::FIRST)['itemsTotal']);
        self::connect(self::SECOND, null, 102);
        $this->subscribe($guest, self::SECOND);
        $this->cart(self::SECOND);
        self::connect(self::ACCOUNT, $userId, 103);
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        self::assertSame('24000', $this->cart(self::ACCOUNT)['itemsTotal']);

        Database::sql('UPDATE session_product_cart SET quantity = ? WHERE id = ?', [2, $unrelatedLine]);
        self::assertSame([], $this->flushCartFacts(
            self::productPriceFact($unrelatedProduct, 9000),
            new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
                EcommerceShopDbContext::sessionProductCarts, (string) $unrelatedLine, [SessionProductCart::quantity => 2],
            )),
        ));
        self::assertSame([], $this->takePageFrames());

        Database::sql('UPDATE session_product_cart SET quantity = ? WHERE id = ?', [5, $guestLine]);
        self::assertSame([], $this->flushCartFacts(
            self::productPriceFact($guestProduct, 9000),
            self::productPriceFact($guestProduct, 10_000),
            self::stockUnitsFact($guestStock, 3),
            new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
                EcommerceShopDbContext::sessionProductCarts, (string) $guestLine, [SessionProductCart::quantity => 5],
            )),
        ));
        $cart = $this->cart(self::FIRST);
        self::assertNull($cart['userId']);
        self::assertSame([5], array_column($cart['lines'], 'quantity'));
        self::assertSame([3], array_column($cart['lines'], 'availableQuantity'));
        self::assertSame('30000', $cart['itemsTotal']);

        self::assertSame([], $this->flushCartFacts(self::stockUnitsFact($accountStock, 1)));
        self::assertSame('12000', $this->cart(self::ACCOUNT)['itemsTotal']);
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($accountProduct, 15_000)));
        self::assertSame('15000', $this->cart(self::ACCOUNT)['itemsTotal']);

        Database::sql('DELETE FROM user_product_cart WHERE id = ?', [$accountLine]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(
            EcommerceShopDbContext::userProductCarts, (string) $accountLine, [ObjectUserProductCart::userId => $userId],
        ))));
        self::assertSame(['userId' => $userId, 'lines' => [], 'itemsTotal' => '0'], $this->cart(self::ACCOUNT));

        $lineId = self::accountLine($userId, $otherProduct, 3);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
            EcommerceShopDbContext::userProductCarts,
            (string) $lineId,
            [UserProductCart::id => $lineId, UserProductCart::user_id => $userId, UserProductCart::product_id => $otherProduct,
                UserProductCart::quantity => 3],
        ))));
        self::assertSame([$otherProduct], array_column($this->cart(self::ACCOUNT)['lines'], 'productId'));

        Database::sql('UPDATE session_product_cart SET session_id = ? WHERE id = ?', [105, $guestLine]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::sessionProductCarts,
            (string) $guestLine,
            [SessionProductCart::session_id => 105],
            previous: [SessionProductCart::session_id => 101],
        ))));
        self::assertSame(['userId' => null, 'lines' => [], 'itemsTotal' => '0'], $this->cart(self::FIRST));
    }

    /** A clear affects the corresponding owner type, even when its current cart is already empty. */
    public function testCartAndStockClearsAreScoped(): void
    {
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        self::guestLine(101, $product, 2);
        $userId = self::person();
        self::accountLine($userId, $product, 1);

        self::connect(self::FIRST, null, 101);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        self::assertSame('24000', $this->cart(self::FIRST)['itemsTotal']);
        self::connect(self::ACCOUNT, $userId, 103);
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        self::assertSame('12000', $this->cart(self::ACCOUNT)['itemsTotal']);

        Database::sql('DELETE FROM session_product_cart');
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncClearedMessageDTO(
            new DbSyncClearedSignalData(EcommerceShopDbContext::sessionProductCarts),
        )));
        self::assertSame(['userId' => null, 'lines' => [], 'itemsTotal' => '0'], $this->cart(self::FIRST));

        Database::sql('DELETE FROM product_warehouse_stock');
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncClearedMessageDTO(
            new DbSyncClearedSignalData(EcommerceShopDbContext::productWarehouseStocks),
        )));
        $cart = $this->cart(self::ACCOUNT);
        self::assertSame([$product], array_column($cart['lines'], 'productId'));
        self::assertSame([0], array_column($cart['lines'], 'availableQuantity'));
        self::assertSame('0', $cart['itemsTotal']);
    }

    /** A sparse fact can fail for one subscriber while another is served, then recover on DB-back. */
    public function testFailedStockMembershipRecoversWithoutResubscribing(): void
    {
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 1);
        $stockId = Database::lastInsertId();
        $userId = self::person();
        self::guestLine(101, $product, 3);
        self::accountLine($userId, $product, 3);
        self::connect(self::FIRST, null, 101);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        self::assertSame('12000', $this->cart(self::FIRST)['itemsTotal']);
        self::connect(self::ACCOUNT, $userId, 103);
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        self::assertSame('12000', $this->cart(self::ACCOUNT)['itemsTotal']);

        $fact = self::stockUnitsFact($stockId, 2);
        self::assertCount(1, $this->flushWithStockReadFailure(true, $fact, $fact));
        $frames = $this->takePageFrames();
        self::assertCount(2, $frames);
        [$error, $answer] = $frames;
        self::assertSame(self::FIRST, $error->targetAcceptKey);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $error->data);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $error->data->httpCode);
        self::assertSame(self::ACCOUNT, $answer->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $answer->data);
        self::assertSame('24000', $answer->data->payload->data[CartPage::DATA_CART]['itemsTotal']);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::ACCOUNT));

        Hilos::$browser->recordDatabaseBack();
        self::assertSame([], $this->flushCartFacts());
        self::assertSame('24000', $this->cart(self::FIRST)['itemsTotal']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
    }

    /** A failed full answer leaves a debt that the next matching fact pays with the same page response. */
    public function testFailedCartSnapshotRecoversOnItsNextFact(): void
    {
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        self::guestLine(101, $product, 2);
        self::connect(self::FIRST, null, 101);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        self::assertSame('24000', $this->cart(self::FIRST)['itemsTotal']);

        self::assertSame([], $this->flushWithStockReadFailure(false, self::productPriceFact($product, 7000)));
        $error = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $error->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));

        self::assertSame([], $this->flushCartFacts(self::productPriceFact($product, 9000)));
        self::assertSame('18000', $this->cart(self::FIRST)['itemsTotal']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
    }

    /** Saved quantities survive 5 → 2 → 0 → 5 availability, and unavailable unique pieces still appear at zero. */
    public function testTotalsUseCurrentStockAndPriceWithoutChangingTheSelection(): void
    {
        $stockProductId = self::product(ProductKind::STOCK);
        $uniqueProductId = self::product(ProductKind::UNIQUE);
        $firstWarehouse = self::warehouse();
        $secondWarehouse = self::warehouse();
        self::stock($stockProductId, $firstWarehouse, 3);
        $firstStock = Database::lastInsertId();
        self::stock($stockProductId, $secondWarehouse, 2);
        $secondStock = Database::lastInsertId();
        self::guestLine(101, $stockProductId, 5);
        self::guestLine(101, $uniqueProductId, 1);
        self::connect(self::FIRST, null, 101);
        $guest = new GuestCartsLibraryAgent();
        $stock = Hilos::$db->productWarehouseStocks->getObjectCollection();
        self::assertNotNull($stock);
        self::assertFalse($stock->isAllLoaded());

        $this->subscribe($guest, self::FIRST);
        foreach ([[5, '60000'], [2, '24000'], [0, '0'], [5, '60000']] as $step => [$available, $total]) {
            if ($step === 1 || $step === 2) {
                $stockId = $step === 1 ? $firstStock : $secondStock;
                Database::sql('DELETE FROM product_warehouse_stock WHERE id = ?', [$stockId]);
                self::assertSame([], $this->flushCartFacts(new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(
                    EcommerceShopDbContext::productWarehouseStocks,
                    (string) $stockId,
                    [EntityProductWarehouseStock::product_id => $stockProductId],
                ))));
            } elseif ($step === 3) {
                self::stock($stockProductId, $firstWarehouse, 5);
                $stockId = Database::lastInsertId();
                self::assertSame([], $this->flushCartFacts(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
                    EcommerceShopDbContext::productWarehouseStocks,
                    (string) $stockId,
                    [EntityProductWarehouseStock::id => $stockId, EntityProductWarehouseStock::product_id => $stockProductId,
                        EntityProductWarehouseStock::warehouse_id => $firstWarehouse, EntityProductWarehouseStock::units => 5],
                ))));
            }
            $cart = $this->cart(self::FIRST);
            self::assertCount(2, $cart['lines']);
            $product = Hilos::$db->products[$stockProductId];
            self::assertSame([
                'productId' => $stockProductId,
                'slug' => $product->slug,
                'name' => $product->name,
                'kind' => ProductKind::STOCK->value,
                'pictureUrl' => HilosFiles::downloadPath($product->pictureFileId, CatalogPictureUploadTarget::VARIANT_SM),
                'price' => 12_000,
                'quantity' => 5,
                'availableQuantity' => $available,
                'lineTotal' => $total,
            ], $cart['lines'][0]);
            self::assertSame($uniqueProductId, $cart['lines'][1]['productId']);
            self::assertSame(ProductKind::UNIQUE->value, $cart['lines'][1]['kind']);
            self::assertSame(1, $cart['lines'][1]['quantity']);
            self::assertSame(0, $cart['lines'][1]['availableQuantity']);
            self::assertSame('0', $cart['lines'][1]['lineTotal']);
            self::assertSame($total, $cart['itemsTotal']);
            self::assertSame(5, (int)Database::sql(
                'SELECT quantity FROM session_product_cart WHERE session_id = ? AND product_id = ?',
                [101, $stockProductId],
            )->field('quantity'));
        }
        self::assertFalse($stock->isAllLoaded());
    }

    /** The whole cart keeps the last kopeck when its total exceeds the browser's safe integer range. */
    public function testLargeTotalsStayExactInTheFirstAndLiveAnswers(): void
    {
        $firstProduct = self::product(ProductKind::STOCK);
        $secondProduct = self::product(ProductKind::STOCK);
        $warehouse = self::warehouse();
        self::stock($firstProduct, $warehouse, 50_000_000);
        self::stock($secondProduct, $warehouse, 50_000_001);
        self::guestLine(101, $firstProduct, 50_000_000);
        self::guestLine(101, $secondProduct, 50_000_001);
        self::productPriceFact($firstProduct, ProductFields::PRICE_MAX);
        self::productPriceFact($secondProduct, ProductFields::PRICE_MAX);
        self::connect(self::FIRST, null, 101);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);

        $cart = $this->cart(self::FIRST);
        self::assertSame('9999999999999999', $cart['itemsTotal']);
        self::assertSame(['4999999950000000', '5000000049999999'], array_column($cart['lines'], 'lineTotal'));
        self::assertSame([ProductFields::PRICE_MAX, ProductFields::PRICE_MAX], array_column($cart['lines'], 'price'));

        self::assertSame([], $this->flushCartFacts(self::productPriceFact($firstProduct, 99_999_997)));
        $cart = $this->cart(self::FIRST);
        self::assertSame('9999999899999999', $cart['itemsTotal']);
        self::assertSame(['4999999850000000', '5000000049999999'], array_column($cart['lines'], 'lineTotal'));
        self::assertSame([99_999_997, ProductFields::PRICE_MAX], array_column($cart['lines'], 'price'));
    }

    /**
     * @param string $acceptKey Connection to identify
     * @param ?int $userId Account behind it, or null for a guest
     * @param ?int $sessionId Session carried by the RT row
     */
    private static function connect(string $acceptKey, ?int $userId, ?int $sessionId): void
    {
        ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey, $userId, $sessionId): void {
            Hilos::$rt->connections->actions->register($acceptKey, $userId, sessionId: $sessionId);
        });
    }

    /** @return int Fixture account ID */
    private static function person(): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Cart shopper']);

        return Database::lastInsertId();
    }

    /**
     * @param int $sessionId Guest session holding the line
     * @param int $productId Product in the cart
     * @param int $quantity Saved selection
     * @return int Inserted row id
     */
    private static function guestLine(int $sessionId, int $productId, int $quantity): int
    {
        Database::sql(
            'INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, ?)',
            [$sessionId, $productId, $quantity],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Account holding the line
     * @param int $productId Product in the cart
     * @param int $quantity Saved selection
     * @return int Inserted row id
     */
    private static function accountLine(int $userId, int $productId, int $quantity): int
    {
        Database::sql(
            'INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, ?)',
            [$userId, $productId, $quantity],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $productId Product whose price is written by the catalog owner
     * @param int $price Current price in kopecks
     * @return WorkerDbSyncUpdatedMessageDTO Applied price fact
     */
    private static function productPriceFact(int $productId, int $price): WorkerDbSyncUpdatedMessageDTO
    {
        Database::sql('UPDATE product SET price = ? WHERE id = ?', [$price, $productId]);

        return new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::products, (string) $productId, [Product::price => $price],
        ));
    }

    /**
     * @param int $stockId Existing stock row id, distinct from its product key
     * @param int $units New warehouse count
     * @return WorkerDbSyncUpdatedMessageDTO Sparse fact as the stock owner sends it
     */
    private static function stockUnitsFact(int $stockId, int $units): WorkerDbSyncUpdatedMessageDTO
    {
        Database::sql('UPDATE product_warehouse_stock SET units = ? WHERE id = ?', [$units, $stockId]);

        return new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::productWarehouseStocks, (string) $stockId, [EntityProductWarehouseStock::units => $units],
        ));
    }

    /** @param ?int $userId New identity on the existing connection */
    private static function bindFirstUser(?int $userId): void
    {
        ExecutionContext::run(new ExecutionFrame(), static function () use ($userId): void {
            Hilos::$rt->connections[self::FIRST]->actions->bindUser($userId);
        });
    }

    /**
     * @param bool $duringMembership Whether the lookup fails before a snapshot can be attempted
     * @param WorkerDTO ...$facts Worker messages leading to the failed read
     * @return list<ContainedFailure> Failures recorded by the browser subscription trap
     */
    private function flushWithStockReadFailure(bool $duringMembership, WorkerDTO ...$facts): array
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $db = Hilos::$db;
        $original = $views->getValue($db);
        $fail = static function () use ($views, $db, $original): never {
            $views->setValue($db, $original);
            throw new DatabaseException('The cart stock read failed');
        };
        $failingView = new class ($original[EcommerceShopDbContext::productWarehouseStocks], $duringMembership, $fail) extends DbCollection {
            public function __construct(
                private readonly ProductWarehouseStocks $original,
                private readonly bool $duringMembership,
                private readonly Closure $fail,
            ) {
            }

            public function productIdAtStockKey(int $stockId): ?int
            {
                if ($this->duringMembership) {
                    ($this->fail)();
                }

                return $this->original->productIdAtStockKey($stockId);
            }

            public function totalsOfProducts(int ...$productIds): ProductStockTotalsCollection
            {
                ($this->fail)();
            }
        };
        $views->setValue($db, array_replace($original, [EcommerceShopDbContext::productWarehouseStocks => $failingView]));
        try {
            return $this->flushCartFacts(...$facts);
        } finally {
            $views->setValue($db, $original);
        }
    }

    /**
     * @param AgentInterface $agent Agent the subscription reached
     * @param string $acceptKey Subscribing connection
     */
    private function subscribe(AgentInterface $agent, string $acceptKey): void
    {
        $this->deliver(
            $agent,
            SignalTypeConstants::PAGE_SUBSCRIBE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, CartPage::PAGE),
            SignalSource::WEBSOCKET,
        );
    }

    /**
     * @param AgentInterface $owner Current cart owner
     * @param string $deliveryId Token confirmed by the connection's master
     */
    private function subscribeTrackedCart(AgentInterface $owner, string $deliveryId): void
    {
        $request = new WebSocketPageSubscribeSignalDTO(self::FIRST, CartPage::PAGE, deliveryId: $deliveryId);
        $identity = PageHandoffIdentity::current(self::FIRST);
        self::assertNotNull($identity);
        $this->deliver($owner, SignalTypeConstants::PAGE_SUBSCRIBE, $request, SignalSource::WEBSOCKET, $identity->toMeta());
        $probe = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(PageHandoffIdentity::PROBE, $probe?->meta[PageHandoffIdentity::META_PROOF]);
        $this->deliver($owner, SignalTypeConstants::PAGE_ACCESS_REASSESS, $request, meta: [
            ...$probe->meta,
            PageHandoffIdentity::META_PROOF => PageHandoffIdentity::GRANTED,
        ]);
        self::assertSame($deliveryId, Hilos::$sr->pageSubscription(self::FIRST)?->deliveryId);
    }

    /**
     * @param AgentInterface $previous Agent the master releases
     * @param AgentInterface $next Agent selected from the RT identity
     */
    private function handoff(AgentInterface $previous, AgentInterface $next): void
    {
        $this->deliver($previous, SignalTypeConstants::PAGE_UNSUBSCRIBE, new WebSocketPageUnsubscribeSignalDTO(self::FIRST));
        $identity = PageHandoffIdentity::current(self::FIRST);
        self::assertNotNull($identity);
        $this->deliver(
            $next, SignalTypeConstants::PAGE_ACCESS_REASSESS,
            new WebSocketPageSubscribeSignalDTO(self::FIRST, CartPage::PAGE),
            meta: $identity->toMeta(),
        );
    }

    /**
     * @param string $acceptKey Connection expecting a full cart
     * @return array{userId: ?int, lines: list<array<string, mixed>>, itemsTotal: string} The wire cart
     */
    private function cart(string $acceptKey): array
    {
        $reply = $this->pageReply($acceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame(CartPage::PAGE, $reply->pageKey);
        $wire = PageResponseSignalData::fromArray($reply->toArray())->payload->data;
        self::assertSame([CartPage::DATA_CART], array_keys($wire));

        return $wire[CartPage::DATA_CART];
    }

    /** @param string $acceptKey Connection that must receive a refusal without a cart */
    private function assertRefusal(string $acceptKey): void
    {
        $reply = $this->pageReply($acceptKey);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $reply);
        self::assertSame(CartPage::PAGE, $reply->page);
        self::assertSame(HttpConstants::HTTP_CONFLICT, $reply->httpCode);
    }

    /**
     * @param string $acceptKey Connection awaiting its single reply
     * @return PageResponseSignalData|PageSubscriptionErrorSignalData Page reply or refusal
     */
    private function pageReply(string $acceptKey): PageResponseSignalData|PageSubscriptionErrorSignalData
    {
        $frames = $this->takePageFrames();
        self::assertCount(1, $frames);
        self::assertSame($acceptKey, $frames[0]->targetAcceptKey);

        return $frames[0]->data;
    }

    /** @return list<WebSocketSignalData> Page frames queued for every connection */
    private function takePageFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData
                && ($data->data instanceof PageResponseSignalData || $data->data instanceof PageSubscriptionErrorSignalData)
            ) {
                $frames[] = $data;
            }
        }

        return $frames;
    }
}
