<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Database\Actions\ProductFields;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\DTO\ProductStockTotalsCollection;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Pages\DTO\CartRemoveLineActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\SessionToken;
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
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
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
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
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
use Hilos\Utils\Helpers\TimeHelper;
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
        $emptySessionId = self::storedSession();
        $otherSessionId = self::storedSession();
        $sessionId = self::storedSession();
        $firstProduct = self::product(ProductKind::STOCK);
        $secondProduct = self::product(ProductKind::STOCK);
        $userId = self::person();
        $firstLine = self::guestLine($sessionId, $secondProduct, 2);
        $secondLine = self::guestLine($sessionId, $firstProduct, 3);
        $otherLine = self::guestLine($otherSessionId, $firstProduct, 1);
        $accountLine = self::accountLine($userId, $secondProduct, 5);
        self::connect(self::FIRST, null, $sessionId);
        self::connect(self::SECOND, null, $otherSessionId);
        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        self::connect(self::EMPTY, null, $emptySessionId);

        $guest = new GuestCartsLibraryAgent();
        $this->subscribe($guest, self::FIRST);
        $first = $this->cart(self::FIRST);
        self::assertNull($first['userId']);
        self::assertSame([$firstLine, $secondLine], array_column($first['lines'], 'lineId'));
        self::assertSame([$secondProduct, $firstProduct], array_column($first['lines'], 'productId'));
        self::assertSame([2, 3], array_column($first['lines'], 'quantity'));
        $this->subscribe($guest, self::SECOND);
        $second = $this->cart(self::SECOND);
        self::assertNull($second['userId']);
        self::assertSame([$otherLine], array_column($second['lines'], 'lineId'));
        self::assertSame([$firstProduct], array_column($second['lines'], 'productId'));
        self::assertSame([1], array_column($second['lines'], 'quantity'));
        $this->subscribe(new UserAgent((string)$userId), self::ACCOUNT);
        $account = $this->cart(self::ACCOUNT);
        self::assertSame($userId, $account['userId']);
        self::assertNull($account['guestCartKey']);
        self::assertSame([$accountLine], array_column($account['lines'], 'lineId'));
        self::assertSame([$secondProduct], array_column($account['lines'], 'productId'));
        self::assertSame([5], array_column($account['lines'], 'quantity'));

        $this->subscribe($guest, self::EMPTY);
        self::assertSame([
            'userId' => null,
            'guestCartKey' => self::expectedGuestCartKey($emptySessionId),
            'lines' => [],
            'itemsTotal' => '0',
            'voucher' => null,
            'total' => '0',
        ], $this->cart(self::EMPTY));
    }

    /** Empty guest carts differ by session, and another connection of the same session sees the same cart. */
    public function testEmptyGuestCartIdentitySurvivesAChangeOfConnection(): void
    {
        $sessionId = self::storedSession();
        $otherSessionId = self::storedSession();
        $guest = new GuestCartsLibraryAgent();
        self::connect(self::FIRST, null, $sessionId);
        self::connect(self::SECOND, null, $otherSessionId);
        self::connect(self::EMPTY, null, $sessionId);

        $this->subscribe($guest, self::FIRST);
        $first = $this->cart(self::FIRST);
        self::assertSame([], $first['lines']);
        self::assertSame(self::expectedGuestCartKey($sessionId), $first['guestCartKey']);
        $this->subscribe($guest, self::SECOND);
        $second = $this->cart(self::SECOND);
        self::assertSame([...$first, 'guestCartKey' => self::expectedGuestCartKey($otherSessionId)], $second);
        self::assertNotSame($first['guestCartKey'], $second['guestCartKey']);

        $this->subscribe($guest, self::EMPTY);
        self::assertSame($first, $this->cart(self::EMPTY));

        Database::sql('UPDATE hilos_session SET cart_epoch = 1 WHERE id = ?', [$sessionId]);
        self::connect(self::ACCOUNT, null, $sessionId);
        $this->subscribe($guest, self::ACCOUNT);
        $next = $this->cart(self::ACCOUNT);
        self::assertSame([...$first, 'guestCartKey' => self::expectedGuestCartKey($sessionId, 1)], $next);
        self::assertNotSame($first['guestCartKey'], $next['guestCartKey']);
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
        $accountSessionId = self::storedSession($userId);
        self::connect(self::ACCOUNT, $userId, $accountSessionId);
        self::guestLine($accountSessionId, self::product(ProductKind::STOCK), 1);
        $this->subscribe($guest, self::ACCOUNT);
        $this->assertRefusal(self::ACCOUNT);

        $this->subscribe(new UserAgent((string)self::person()), self::ACCOUNT);
        $this->assertRefusal(self::ACCOUNT);
    }

    /** The same page key and connection get a whole cart from each new owner after the runtime identity changes. */
    public function testIdentityReassessmentReplacesTheCartOnTheSamePage(): void
    {
        $sessionId = self::storedSession();
        $guestProduct = self::product(ProductKind::STOCK);
        $accountProduct = self::product(ProductKind::STOCK);
        $userId = self::person();
        self::guestLine($sessionId, $guestProduct, 1);
        self::accountLine($userId, $accountProduct, 2);
        $warehouse = self::warehouse();
        self::stock($guestProduct, $warehouse, 5);
        self::stock($accountProduct, $warehouse, 5);
        $guestVoucher = $this->voucher('CARTGUEST10', 10);
        self::guestVoucher($sessionId, $guestVoucher->id);
        self::accountVoucher($userId, $this->voucher('CARTOWNER20', 20)->id);
        self::connect(self::FIRST, null, $sessionId);
        $guest = new GuestCartsLibraryAgent();
        $account = new UserAgent((string)$userId);
        $this->subscribe($guest, self::FIRST);
        $cart = $this->cart(self::FIRST);
        self::assertSame([$guestProduct], array_column($cart['lines'], 'productId'));
        self::assertSame(['code' => 'CARTGUEST10', 'active' => true, 'discount' => '1200'], $cart['voucher']);
        self::assertSame('10800', $cart['total']);

        self::bindFirstUser($userId);
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($guestProduct, 8000), self::productPriceFact($accountProduct, 9000)));
        self::assertSame([], $this->takePageFrames());
        $this->handoff($guest, $account);
        $cart = $this->cart(self::FIRST);
        self::assertSame($userId, $cart['userId']);
        self::assertSame([$accountProduct], array_column($cart['lines'], 'productId'));
        self::assertSame(['code' => 'CARTOWNER20', 'active' => true, 'discount' => '3600'], $cart['voucher']);
        self::assertSame('14400', $cart['total']);
        self::assertSame((string)$userId, Hilos::$sr->pageSubscription(self::FIRST)?->agentIndex);
        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $guestVoucher->actions->setActive(false))));
        self::assertSame([], $this->takePageFrames());

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
        self::assertSame(['code' => 'CARTGUEST10', 'active' => false, 'discount' => '0'], $cart['voucher']);
        self::assertSame($cart['itemsTotal'], $cart['total']);
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
        $userId = $registered ? self::person() : null;
        $sessionId = self::storedSession($userId);
        if ($userId === null) {
            self::guestLine($sessionId, $product, 1);
            self::guestLine(self::storedSession(), $otherProduct, 1);
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
                EcommerceShopDbContext::sessionVoucherCarts,
                EcommerceShopDbContext::userVoucherCarts,
                EcommerceShopDbContext::vouchers,
                HilosDbContext::sessions,
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
        $sessionId = self::storedSession();
        $otherSessionId = self::storedSession();
        self::connect(self::FIRST, null, $sessionId);
        self::guestLine($sessionId, self::product(ProductKind::STOCK), 1);
        $guest = new GuestCartsLibraryAgent();
        $expected = new PageHandoffIdentity(EcommerceShopRtContext::connections, null, $otherSessionId);
        $this->deliver(
            $guest, SignalTypeConstants::PAGE_ACCESS_REASSESS,
            new WebSocketPageSubscribeSignalDTO(self::FIRST, CartPage::PAGE),
            meta: $expected->toMeta(),
        );
        self::assertSame([], $this->takePageFrames());
        self::assertNull(Hilos::$sr->pageSubscription(self::FIRST));

        $this->applyCartConnectionFact(new RtSyncUpdatedSignalData(
            EcommerceShopRtContext::connections,
            self::FIRST,
            [
                StateHilosSessionConnection::sessionId => $otherSessionId,
                StateHilosSessionConnection::sessionToken => Hilos::$db->sessions[$otherSessionId]->token,
            ],
        ));
        self::assertSame([
            'userId' => null,
            'guestCartKey' => self::expectedGuestCartKey($otherSessionId),
            'lines' => [],
            'itemsTotal' => '0',
            'voucher' => null,
            'total' => '0',
        ], $this->cart(self::FIRST));
    }

    /** An expired saved guest session cannot answer even if its RT token still matches. */
    public function testExpiredGuestSessionCannotAnswer(): void
    {
        $sessionId = self::storedSession();
        self::connect(self::FIRST, null, $sessionId);
        Database::sql('UPDATE hilos_session SET expires_at = ? WHERE id = ?', [TimeHelper::nowMs(), $sessionId]);

        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        $this->assertRefusal(self::FIRST);
    }

    /** A token rotation during the stock read prevents the assembled snapshot from being sent. */
    public function testGuestSnapshotRefusesABindingChangedDuringAssembly(): void
    {
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        self::guestLine($sessionId, $product, 2);
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        self::assertSame(self::expectedGuestCartKey($sessionId), $this->cart(self::FIRST)['guestCartKey']);

        $rotate = static function () use ($sessionId): void {
            Database::sql('UPDATE hilos_session SET token = ? WHERE id = ?', [SessionToken::mint(), $sessionId]);
        };
        self::assertSame([], $this->flushBeforeStockRead($rotate, false, self::productPriceFact($product, 9000)));
        $this->assertRefusal(self::FIRST);
    }

    /** The first answer and live facts use the same active guest cart pair. */
    public function testGuestCartPageObservesOnlyTheCurrentEpoch(): void
    {
        $sessionId = 101;
        $oldProduct = self::product(ProductKind::STOCK);
        $newProduct = self::product(ProductKind::STOCK);
        $oldLine = self::guestLine($sessionId, $oldProduct, 2);
        $newLine = self::guestLine($sessionId, $newProduct, 1, 1);
        self::stock($oldProduct, self::warehouse(), 5);
        $oldStock = Database::lastInsertId();
        self::connect(self::FIRST, null, $sessionId);
        Database::sql('UPDATE hilos_session SET cart_epoch = 1 WHERE id = ?', [$sessionId]);

        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        $cart = $this->cart(self::FIRST);
        self::assertSame([$newLine], array_column($cart['lines'], 'lineId'));
        self::assertSame(self::expectedGuestCartKey($sessionId, 1), $cart['guestCartKey']);
        self::assertSame([], $this->flushCartFacts(
            self::productPriceFact($oldProduct, 9000),
            self::stockUnitsFact($oldStock, 3),
            new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
                EcommerceShopDbContext::sessionProductCarts, (string)$oldLine, [
                    SessionProductCart::session_id => $sessionId, SessionProductCart::cart_epoch => 0, SessionProductCart::quantity => 2,
                ],
            )),
        ));
        self::assertSame([], $this->takePageFrames());

        Database::sql('UPDATE session_product_cart SET quantity = 3 WHERE id = ?', [$newLine]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::sessionProductCarts, (string)$newLine, [SessionProductCart::quantity => 3],
        ))));
        $cart = $this->cart(self::FIRST);
        self::assertSame([3], array_column($cart['lines'], 'quantity'));
        self::assertSame(self::expectedGuestCartKey($sessionId, 1), $cart['guestCartKey']);

        Database::sql('DELETE FROM session_product_cart WHERE id = ?', [$oldLine]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(
            EcommerceShopDbContext::sessionProductCarts, (string)$oldLine, [
                SessionProductCart::session_id => $sessionId, SessionProductCart::cart_epoch => 0,
            ],
        ))));
        self::assertSame([], $this->takePageFrames());
    }

    /** Grouped stock, price and quantity facts refresh only their owner's cart, with final values. */
    public function testLiveCartFactsAreAddressedAndGrouped(): void
    {
        $unrelatedSessionId = self::storedSession();
        $otherSessionId = self::storedSession();
        $sessionId = self::storedSession();
        $guestProduct = self::product(ProductKind::STOCK);
        $otherProduct = self::product(ProductKind::STOCK);
        $accountProduct = self::product(ProductKind::STOCK);
        $unrelatedProduct = self::product(ProductKind::STOCK);
        $userId = self::person();
        $guestLine = self::guestLine($sessionId, $guestProduct, 2);
        self::guestLine($otherSessionId, $otherProduct, 1);
        $unrelatedLine = self::guestLine($unrelatedSessionId, $guestProduct, 1);
        $accountLine = self::accountLine($userId, $accountProduct, 2);
        $warehouse = self::warehouse();
        self::stock($guestProduct, $warehouse, 5);
        $guestStock = Database::lastInsertId();
        self::stock($accountProduct, $warehouse, 4);
        $accountStock = Database::lastInsertId();

        $guest = new GuestCartsLibraryAgent();
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe($guest, self::FIRST);
        self::assertSame('24000', $this->cart(self::FIRST)['itemsTotal']);
        self::connect(self::SECOND, null, $otherSessionId);
        $this->subscribe($guest, self::SECOND);
        $this->cart(self::SECOND);
        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
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
        self::assertSame([
            'userId' => $userId,
            'guestCartKey' => null,
            'lines' => [],
            'itemsTotal' => '0',
            'voucher' => null,
            'total' => '0',
        ], $this->cart(self::ACCOUNT));

        $lineId = self::accountLine($userId, $otherProduct, 3);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
            EcommerceShopDbContext::userProductCarts,
            (string) $lineId,
            [UserProductCart::id => $lineId, UserProductCart::user_id => $userId, UserProductCart::product_id => $otherProduct,
                UserProductCart::quantity => 3],
        ))));
        self::assertSame([$otherProduct], array_column($this->cart(self::ACCOUNT)['lines'], 'productId'));

        $recipientSessionId = self::storedSession();
        Database::sql('UPDATE session_product_cart SET session_id = ? WHERE id = ?', [$recipientSessionId, $guestLine]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::sessionProductCarts,
            (string) $guestLine,
            [SessionProductCart::session_id => $recipientSessionId],
            previous: [SessionProductCart::session_id => $sessionId],
        ))));
        self::assertSame([
            'userId' => null,
            'guestCartKey' => self::expectedGuestCartKey($sessionId),
            'lines' => [],
            'itemsTotal' => '0',
            'voucher' => null,
            'total' => '0',
        ], $this->cart(self::FIRST));
    }

    /** Guest Remove deletes the selected line without changing other lines or stock. */
    public function testGuestRemovalDeletesOnlyTheSelectedLine(): void
    {
        $sessionId = self::storedSession();
        $otherSessionId = self::storedSession();
        $removedProduct = self::product(ProductKind::STOCK);
        $remainingProduct = self::product(ProductKind::STOCK);
        $warehouse = self::warehouse();
        self::stock($removedProduct, $warehouse, 5);
        self::stock($remainingProduct, $warehouse, 3);
        $removed = self::guestLine($sessionId, $removedProduct, 2);
        $remaining = self::guestLine($sessionId, $remainingProduct, 1);
        $neighbor = self::guestLine($otherSessionId, $removedProduct, 3);

        $guest = new GuestCartsLibraryAgent();
        try {
            OwnershipDeclaration::claimAll($guest);
            self::connect(self::FIRST, null, $sessionId);
            self::connect(self::SECOND, null, $otherSessionId);
            $this->subscribe($guest, self::FIRST);
            $cart = $this->cart(self::FIRST);
            self::assertSame([$removed, $remaining], array_column($cart['lines'], 'lineId'));
            self::assertSame('36000', $cart['itemsTotal']);
            $this->subscribe($guest, self::SECOND);
            self::assertSame([$neighbor], array_column($this->cart(self::SECOND)['lines'], 'lineId'));

            $reply = $this->removeLine($guest, self::FIRST, $cart['lines'][0]['lineId'], 'guest');
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
            self::assertNull($reply->reply);
            self::assertNull(Database::sql('SELECT id FROM session_product_cart WHERE id = ?', [$removed])->field('id'));
            self::assertSame(1, (int)Database::sql('SELECT quantity FROM session_product_cart WHERE id = ?', [$remaining])->field('quantity'));
            self::assertSame(3, (int)Database::sql('SELECT quantity FROM session_product_cart WHERE id = ?', [$neighbor])->field('quantity'));
            self::assertSame(5, Hilos::$db->productWarehouseStocks->totalsOfProducts($removedProduct)->of($removedProduct)->unitCount);
            self::assertNotNull(Hilos::$db->products[$removedProduct] ?? null);
        } finally {
            TruthSourceRegistry::unregisterAgent($guest->getId());
        }
    }

    /** Account Remove deletes its unavailable line while another account keeps the same product. */
    public function testAccountRemovalKeepsAnotherAccountsLine(): void
    {
        $userId = self::person();
        $otherUserId = self::person();
        $sessionId = self::storedSession($userId);
        $otherSessionId = self::storedSession($otherUserId);
        $product = self::product(ProductKind::STOCK);
        $lineId = self::accountLine($userId, $product, 2);
        $neighbor = self::accountLine($otherUserId, $product, 1);
        $account = new UserAgent((string)$userId);
        try {
            OwnershipDeclaration::claimAll($account);
            self::connect(self::ACCOUNT, $userId, $sessionId);
            self::connect(self::SECOND, $otherUserId, $otherSessionId);
            $this->subscribe(new UserAgent((string)$otherUserId), self::SECOND);
            self::assertSame([$neighbor], array_column($this->cart(self::SECOND)['lines'], 'lineId'));
            $this->subscribe($account, self::ACCOUNT);
            $cart = $this->cart(self::ACCOUNT);
            self::assertSame([$lineId], array_column($cart['lines'], 'lineId'));
            self::assertSame([0], array_column($cart['lines'], 'availableQuantity'));

            $reply = $this->removeLine($account, self::ACCOUNT, $cart['lines'][0]['lineId'], 'account');
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
            self::assertNull($reply->reply);
            self::assertNull(Database::sql('SELECT id FROM user_product_cart WHERE id = ?', [$lineId])->field('id'));
            self::assertSame(1, (int)Database::sql('SELECT quantity FROM user_product_cart WHERE id = ?', [$neighbor])->field('quantity'));
            self::assertNotNull(Hilos::$db->products[$product] ?? null);
        } finally {
            TruthSourceRegistry::unregisterAgent($account->getId());
        }
    }

    /** A saved sign-out takes effect before the connection and subscription hear about it. */
    public function testAccountRemovalRefusesASessionAlreadyUnboundInStorage(): void
    {
        $userId = self::person();
        $sessionId = self::storedSession($userId);
        $product = self::product(ProductKind::STOCK);
        $lineId = self::accountLine($userId, $product, 2);
        $account = new UserAgent((string)$userId);
        try {
            OwnershipDeclaration::claimAll($account);
            self::connect(self::FIRST, $userId, $sessionId);
            $this->subscribe($account, self::FIRST);
            self::assertSame([$lineId], array_column($this->cart(self::FIRST)['lines'], 'lineId'));
            $subscription = Hilos::$sr->pageSubscription(self::FIRST);
            self::assertNotNull($subscription);
            self::assertSame($userId, Hilos::$db->sessions[$sessionId]->userId);

            // Arrange the saved sign-out without delivering its sync to this worker.
            Database::sql('UPDATE hilos_session SET user_id = NULL WHERE id = ?', [$sessionId]);
            self::assertSame(
                [['user_id' => null]],
                Database::sql('SELECT user_id FROM hilos_session WHERE id = ?', [$sessionId])->rows(),
            );
            self::assertSame($userId, Hilos::$db->sessions[$sessionId]->userId);
            self::assertSame($userId, Hilos::$rt->connections[self::FIRST]?->userId);
            self::assertSame($sessionId, Hilos::$rt->connections[self::FIRST]?->sessionId);
            self::assertSame($subscription, Hilos::$sr->pageSubscription(self::FIRST));

            $this->assertRemovalRefused($account, $lineId, 'account');
            self::assertSame(2, (int)Database::sql('SELECT quantity FROM user_product_cart WHERE id = ?', [$lineId])->field('quantity'));
            self::assertSame([], $this->flushCartFacts());
            self::assertSame([], $this->takePageFrames());
        } finally {
            TruthSourceRegistry::unregisterAgent($account->getId());
        }
    }

    /** A stored expiry closes Remove while the connection and subscription still belong to the account. */
    public function testAccountRemovalRefusesAnExpiredStoredSession(): void
    {
        $userId = self::person();
        $sessionId = self::storedSession($userId);
        $product = self::product(ProductKind::STOCK);
        $lineId = self::accountLine($userId, $product, 2);
        $account = new UserAgent((string)$userId);
        try {
            OwnershipDeclaration::claimAll($account);
            self::connect(self::FIRST, $userId, $sessionId);
            $this->subscribe($account, self::FIRST);
            self::assertSame([$lineId], array_column($this->cart(self::FIRST)['lines'], 'lineId'));
            $subscription = Hilos::$sr->pageSubscription(self::FIRST);
            self::assertNotNull($subscription);
            self::assertTrue(Hilos::$db->sessions->isStoredActiveForUser($sessionId, $userId));

            $expiresAt = 2_000;
            Database::sql('UPDATE hilos_session SET expires_at = ? WHERE id = ?', [$expiresAt, $sessionId]);
            self::assertSame(
                [['user_id' => (string)$userId, 'expires_at' => (string)$expiresAt]],
                Database::sql('SELECT user_id, expires_at FROM hilos_session WHERE id = ?', [$sessionId])->rows(),
            );
            self::assertSame($userId, Hilos::$rt->connections[self::FIRST]?->userId);
            self::assertSame($sessionId, Hilos::$rt->connections[self::FIRST]?->sessionId);
            self::assertSame($subscription, Hilos::$sr->pageSubscription(self::FIRST));

            $this->assertRemovalRefused($account, $lineId, CartRemoveLineActionDTO::ACCOUNT);
            self::assertSame(2, (int)Database::sql('SELECT quantity FROM user_product_cart WHERE id = ?', [$lineId])->field('quantity'));
            self::assertSame([], $this->flushCartFacts());
            self::assertSame([], $this->takePageFrames());
        } finally {
            TruthSourceRegistry::unregisterAgent($account->getId());
        }
    }

    /** Foreign and superseded line IDs and an old cart kind all receive the same refusal. */
    public function testRemovalRefusesForeignStaleAndWrongKindTargets(): void
    {
        $sessionId = self::storedSession();
        $userId = self::person();
        $product = self::product(ProductKind::STOCK);
        $oldLine = self::guestLine($sessionId, $product, 2);
        $foreignLine = self::guestLine(self::storedSession(), $product, 4);
        $accountLine = self::accountLine($userId, $product, 3);
        $guest = new GuestCartsLibraryAgent();
        $account = new UserAgent((string)$userId);
        self::connect(self::FIRST, null, $sessionId);
        self::assertSame([], Hilos::$sr->getDestinations(self::removeLineSignal(self::FIRST, $oldLine, 'guest')));
        $this->subscribe($guest, self::FIRST);
        self::assertSame([$oldLine], array_column($this->cart(self::FIRST)['lines'], 'lineId'));
        $this->assertRemovalRefused($guest, $foreignLine, 'guest');

        Database::sql('DELETE FROM session_product_cart WHERE id = ?', [$oldLine]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(
            EcommerceShopDbContext::sessionProductCarts, (string)$oldLine, [
                SessionProductCart::session_id => $sessionId, SessionProductCart::cart_epoch => 0,
            ],
        ))));
        self::assertSame([], $this->cart(self::FIRST)['lines']);
        $this->assertRemovalRefused($guest, $oldLine, 'guest');
        $replacement = self::guestLine($sessionId, $product, 5);
        self::assertNotSame($oldLine, $replacement);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
            EcommerceShopDbContext::sessionProductCarts,
            (string)$replacement,
            [SessionProductCart::id => $replacement, SessionProductCart::session_id => $sessionId,
                SessionProductCart::cart_epoch => 0, SessionProductCart::product_id => $product, SessionProductCart::quantity => 5],
        ))));
        self::assertSame([$replacement], array_column($this->cart(self::FIRST)['lines'], 'lineId'));
        $this->assertRemovalRefused($guest, $oldLine, 'guest');

        self::bindFirstUser($userId);
        $this->assertRemovalRefused($guest, $replacement, 'guest');
        $this->handoff($guest, $account);
        self::assertSame([$accountLine], array_column($this->cart(self::FIRST)['lines'], 'lineId'));
        $this->assertRemovalRefused($account, $accountLine, 'guest');

        self::assertSame(4, (int)Database::sql('SELECT quantity FROM session_product_cart WHERE id = ?', [$foreignLine])->field('quantity'));
        self::assertSame(5, (int)Database::sql('SELECT quantity FROM session_product_cart WHERE id = ?', [$replacement])->field('quantity'));
        self::assertSame(3, (int)Database::sql('SELECT quantity FROM user_product_cart WHERE id = ?', [$accountLine])->field('quantity'));
    }

    /** A clear affects the corresponding owner type, even when its current cart is already empty. */
    public function testCartAndStockClearsAreScoped(): void
    {
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        self::guestLine($sessionId, $product, 2);
        $userId = self::person();
        self::accountLine($userId, $product, 1);

        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        self::assertSame('24000', $this->cart(self::FIRST)['itemsTotal']);
        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        self::assertSame('12000', $this->cart(self::ACCOUNT)['itemsTotal']);

        Database::sql('DELETE FROM session_product_cart');
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncClearedMessageDTO(
            new DbSyncClearedSignalData(EcommerceShopDbContext::sessionProductCarts),
        )));
        self::assertSame([
            'userId' => null,
            'guestCartKey' => self::expectedGuestCartKey($sessionId),
            'lines' => [],
            'itemsTotal' => '0',
            'voucher' => null,
            'total' => '0',
        ], $this->cart(self::FIRST));

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
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 1);
        $stockId = Database::lastInsertId();
        $userId = self::person();
        self::guestLine($sessionId, $product, 3);
        self::accountLine($userId, $product, 3);
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        self::assertSame('12000', $this->cart(self::FIRST)['itemsTotal']);
        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
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
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        self::guestLine($sessionId, $product, 2);
        self::connect(self::FIRST, null, $sessionId);
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

    /** A price fact refuses a signed-out account until its saved session is bound again. */
    public function testAccountSnapshotRefusesAnUnboundSessionAndRecoversOnItsNextFact(): void
    {
        $userId = self::person();
        $sessionId = self::storedSession($userId);
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        $lineId = self::accountLine($userId, $product, 2);
        self::accountVoucher($userId, $this->voucher('CARTLIVE20', 20)->id);
        self::connect(self::FIRST, $userId, $sessionId);
        $this->subscribe(new UserAgent((string)$userId), self::FIRST);
        $cart = $this->cart(self::FIRST);
        self::assertSame([$lineId], array_column($cart['lines'], 'lineId'));
        self::assertSame('19200', $cart['total']);
        $subscription = Hilos::$sr->pageSubscription(self::FIRST);
        self::assertNotNull($subscription);

        Database::sql('UPDATE hilos_session SET user_id = NULL WHERE id = ?', [$sessionId]);
        self::assertSame($userId, Hilos::$rt->connections[self::FIRST]?->userId);
        self::assertSame($sessionId, Hilos::$rt->connections[self::FIRST]?->sessionId);
        self::assertSame($subscription, Hilos::$sr->pageSubscription(self::FIRST));

        $readStock = false;
        $markStockRead = static function () use (&$readStock): void {
            $readStock = true;
        };
        self::assertSame([], $this->flushBeforeStockRead($markStockRead, false, self::productPriceFact($product, 7000)));
        $this->assertRefusal(self::FIRST);
        self::assertFalse($readStock);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));

        Database::sql('UPDATE hilos_session SET user_id = ? WHERE id = ?', [$userId, $sessionId]);
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($product, 9000)));
        $cart = $this->cart(self::FIRST);
        self::assertSame($userId, $cart['userId']);
        self::assertNull($cart['guestCartKey']);
        self::assertSame([$lineId], array_column($cart['lines'], 'lineId'));
        self::assertSame([9000], array_column($cart['lines'], 'price'));
        self::assertSame('18000', $cart['itemsTotal']);
        self::assertSame(['code' => 'CARTLIVE20', 'active' => true, 'discount' => '3600'], $cart['voucher']);
        self::assertSame('14400', $cart['total']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
    }

    /** A saved sign-out during the stock read prevents the assembled account snapshot from being sent. */
    public function testAccountSnapshotRefusesABindingChangedDuringAssembly(): void
    {
        $userId = self::person();
        $sessionId = self::storedSession($userId);
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        $lineId = self::accountLine($userId, $product, 2);
        self::connect(self::FIRST, $userId, $sessionId);
        $this->subscribe(new UserAgent((string)$userId), self::FIRST);
        self::assertSame([$lineId], array_column($this->cart(self::FIRST)['lines'], 'lineId'));

        $unbind = static function () use ($sessionId): void {
            Database::sql('UPDATE hilos_session SET user_id = NULL WHERE id = ?', [$sessionId]);
        };
        self::assertSame([], $this->flushBeforeStockRead($unbind, false, self::productPriceFact($product, 9000)));
        $this->assertRefusal(self::FIRST);
    }

    /** Saved quantities survive 5 → 2 → 0 → 5 availability, and unavailable unique pieces still appear at zero. */
    public function testTotalsUseCurrentStockAndPriceWithoutChangingTheSelection(): void
    {
        $sessionId = self::storedSession();
        $stockProductId = self::product(ProductKind::STOCK);
        $uniqueProductId = self::product(ProductKind::UNIQUE);
        $firstWarehouse = self::warehouse();
        $secondWarehouse = self::warehouse();
        self::stock($stockProductId, $firstWarehouse, 3);
        $firstStock = Database::lastInsertId();
        self::stock($stockProductId, $secondWarehouse, 2);
        $secondStock = Database::lastInsertId();
        $stockLine = self::guestLine($sessionId, $stockProductId, 5);
        self::guestLine($sessionId, $uniqueProductId, 1);
        self::connect(self::FIRST, null, $sessionId);
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
                'lineId' => $stockLine,
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
                [$sessionId, $stockProductId],
            )->field('quantity'));
        }
        self::assertFalse($stock->isAllLoaded());
    }

    /** The whole cart keeps the last kopeck when its total exceeds the browser's safe integer range. */
    public function testLargeTotalsStayExactInTheFirstAndLiveAnswers(): void
    {
        $sessionId = self::storedSession();
        $firstProduct = self::product(ProductKind::STOCK);
        $secondProduct = self::product(ProductKind::STOCK);
        $warehouse = self::warehouse();
        self::stock($firstProduct, $warehouse, 50_000_000);
        self::stock($secondProduct, $warehouse, 50_000_001);
        self::guestLine($sessionId, $firstProduct, 50_000_000);
        self::guestLine($sessionId, $secondProduct, 50_000_001);
        self::productPriceFact($firstProduct, ProductFields::PRICE_MAX);
        self::productPriceFact($secondProduct, ProductFields::PRICE_MAX);
        $voucher = $this->voucher('CARTPAGE1', 1);
        self::guestVoucher($sessionId, $voucher->id);
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);

        $cart = $this->cart(self::FIRST);
        self::assertSame('9999999999999999', $cart['itemsTotal']);
        self::assertSame(['code' => 'CARTPAGE1', 'active' => true, 'discount' => '100000000000000'], $cart['voucher']);
        self::assertSame('9899999999999999', $cart['total']);
        self::assertSame(['4999999950000000', '5000000049999999'], array_column($cart['lines'], 'lineTotal'));
        self::assertSame([ProductFields::PRICE_MAX, ProductFields::PRICE_MAX], array_column($cart['lines'], 'price'));

        self::assertSame([], $this->flushCartFacts(self::productPriceFact($firstProduct, 99_999_997)));
        $cart = $this->cart(self::FIRST);
        self::assertSame('9999999899999999', $cart['itemsTotal']);
        self::assertSame(['4999999850000000', '5000000049999999'], array_column($cart['lines'], 'lineTotal'));
        self::assertSame([99_999_997, ProductFields::PRICE_MAX], array_column($cart['lines'], 'price'));
        self::assertSame('99999999000000', $cart['voucher']['discount'] ?? null);
        self::assertSame('9899999900999999', $cart['total']);

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $voucher->actions->setActive(false))));
        $cart = $this->cart(self::FIRST);
        self::assertSame(['code' => 'CARTPAGE1', 'active' => false, 'discount' => '0'], $cart['voucher']);
        self::assertSame('9999999899999999', $cart['total']);
        self::assertSame($cart['itemsTotal'], $cart['total']);

        self::assertSame([], $this->flushCartFacts(self::guestVoucherRemoved($sessionId)));
        $cart = $this->cart(self::FIRST);
        self::assertNull($cart['voucher']);
        self::assertSame('9999999899999999', $cart['total']);
        self::assertSame($cart['itemsTotal'], $cart['total']);
    }

    /** Cold answers carry the selected voucher exactly: active, switched off, over a partial and over a zero remainder. */
    public function testFirstAnswersCarryTheVoucherAndTheExactTotal(): void
    {
        $unrelatedSessionId = self::storedSession();
        $otherSessionId = self::storedSession();
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        $unavailable = self::product(ProductKind::STOCK);
        self::productPriceFact($product, 1002);
        self::stock($product, self::warehouse(), 5);
        $userId = self::person();
        $active = $this->voucher('CARTPAGE25', 25);
        $inactive = $this->voucher('CARTPAGEOFF10', 10);
        $this->voucherFacts(static fn () => $inactive->actions->setActive(false));
        self::guestLine($sessionId, $product, 1);
        self::guestVoucher($sessionId, $active->id);
        self::accountLine($userId, $product, 1);
        self::accountVoucher($userId, $inactive->id);
        self::guestLine($otherSessionId, $product, 7);
        self::guestVoucher($otherSessionId, $active->id);
        self::guestLine($unrelatedSessionId, $unavailable, 2);
        self::guestVoucher($unrelatedSessionId, $active->id);
        $guest = new GuestCartsLibraryAgent();

        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe($guest, self::FIRST);
        $cart = $this->cart(self::FIRST);
        self::assertSame('1002', $cart['itemsTotal']);
        self::assertSame(['code' => 'CARTPAGE25', 'active' => true, 'discount' => '251'], $cart['voucher']);
        self::assertSame('751', $cart['total']);

        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        $cart = $this->cart(self::ACCOUNT);
        self::assertSame(['code' => 'CARTPAGEOFF10', 'active' => false, 'discount' => '0'], $cart['voucher']);
        self::assertSame('1002', $cart['total']);

        self::connect(self::SECOND, null, $otherSessionId);
        $this->subscribe($guest, self::SECOND);
        $cart = $this->cart(self::SECOND);
        self::assertSame([7], array_column($cart['lines'], 'quantity'));
        self::assertSame('5010', $cart['itemsTotal']);
        self::assertSame(['code' => 'CARTPAGE25', 'active' => true, 'discount' => '1253'], $cart['voucher']);
        self::assertSame('3757', $cart['total']);

        self::connect(self::EMPTY, null, $unrelatedSessionId);
        $this->subscribe($guest, self::EMPTY);
        $cart = $this->cart(self::EMPTY);
        self::assertSame([0], array_column($cart['lines'], 'availableQuantity'));
        self::assertSame('0', $cart['itemsTotal']);
        self::assertSame(['code' => 'CARTPAGE25', 'active' => true, 'discount' => '0'], $cart['voucher']);
        self::assertSame('0', $cart['total']);
    }

    /** The catalog writer's edits re-answer, once a flush, exactly the open carts holding that voucher. */
    public function testVoucherCatalogChangesReanswerOnlyTheCartsHoldingIt(): void
    {
        $otherSessionId = self::storedSession();
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        $otherProduct = self::product(ProductKind::STOCK);
        $warehouse = self::warehouse();
        self::stock($product, $warehouse, 5);
        $stockId = Database::lastInsertId();
        self::stock($otherProduct, $warehouse, 5);
        $userId = self::person();
        $shared = $this->voucher('CARTPAGE25', 25);
        $other = $this->voucher('CARTPAGE10', 10);
        self::guestLine($sessionId, $product, 2);
        self::guestVoucher($sessionId, $shared->id);
        self::accountLine($userId, $product, 2);
        self::accountVoucher($userId, $shared->id);
        self::guestLine($otherSessionId, $otherProduct, 2);
        self::guestVoucher($otherSessionId, $other->id);
        $guest = new GuestCartsLibraryAgent();
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe($guest, self::FIRST);
        self::assertSame('18000', $this->cart(self::FIRST)['total']);
        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        self::assertSame('18000', $this->cart(self::ACCOUNT)['total']);
        self::connect(self::SECOND, null, $otherSessionId);
        $this->subscribe($guest, self::SECOND);
        self::assertSame('21600', $this->cart(self::SECOND)['total']);
        self::assertContains(
            EcommerceShopDbContext::vouchers,
            SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page(self::FIRST), SourceChange::KIND_DB),
        );

        $facts = $this->voucherFacts(static fn () => $shared->actions->setActive(false));
        $facts[] = self::productPriceFact($product, 10_000);
        self::assertSame([], $this->flushCartFacts(...$facts));
        foreach ($this->cartsByConnection([self::FIRST, self::ACCOUNT]) as $cart) {
            self::assertSame(['code' => 'CARTPAGE25', 'active' => false, 'discount' => '0'], $cart['voucher']);
            self::assertSame('20000', $cart['itemsTotal']);
            self::assertSame('20000', $cart['total']);
        }

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $shared->actions->setActive(true))));
        foreach ($this->cartsByConnection([self::FIRST, self::ACCOUNT]) as $cart) {
            self::assertSame(['code' => 'CARTPAGE25', 'active' => true, 'discount' => '5000'], $cart['voucher']);
            self::assertSame('15000', $cart['total']);
        }

        $facts = $this->voucherFacts(static fn () => $shared->actions->update('CARTPAGE30', 30, true));
        $facts[] = self::stockUnitsFact($stockId, 1);
        self::assertSame([], $this->flushCartFacts(...$facts));
        foreach ($this->cartsByConnection([self::FIRST, self::ACCOUNT]) as $cart) {
            self::assertSame([1], array_column($cart['lines'], 'availableQuantity'));
            self::assertSame('10000', $cart['itemsTotal']);
            self::assertSame(['code' => 'CARTPAGE30', 'active' => true, 'discount' => '3000'], $cart['voucher']);
            self::assertSame('7000', $cart['total']);
        }
    }

    /** A binding fact names its owner by key, so only that owner's cart is answered; a clear answers its whole kind. */
    public function testVoucherBindingFactsAreAddressedToTheirOwner(): void
    {
        $otherSessionId = self::storedSession();
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 5);
        $userId = self::person();
        $first = $this->voucher('CARTPAGE25', 25);
        $second = $this->voucher('CARTPAGE50', 50);
        self::guestLine($sessionId, $product, 1);
        self::guestLine($otherSessionId, $product, 1);
        self::accountLine($userId, $product, 1);
        $guest = new GuestCartsLibraryAgent();
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe($guest, self::FIRST);
        self::assertNull($this->cart(self::FIRST)['voucher']);
        self::connect(self::SECOND, null, $otherSessionId);
        $this->subscribe($guest, self::SECOND);
        self::assertNull($this->cart(self::SECOND)['voucher']);
        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        self::assertSame('12000', $this->cart(self::ACCOUNT)['total']);

        self::guestVoucher($otherSessionId, $first->id);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
            EcommerceShopDbContext::sessionVoucherCarts,
            (string)$otherSessionId,
            [SessionVoucherCart::session_id => $otherSessionId, SessionVoucherCart::voucher_id => $first->id],
        ))));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertSame(['code' => 'CARTPAGE25', 'active' => true, 'discount' => '3000'], $cart['voucher']);
        self::assertSame('9000', $cart['total']);

        self::accountVoucher($userId, $first->id);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
            EcommerceShopDbContext::userVoucherCarts,
            (string) $userId,
            [UserVoucherCart::user_id => $userId, UserVoucherCart::voucher_id => $first->id],
        ))));
        self::assertSame('9000', $this->cartsByConnection([self::ACCOUNT])[self::ACCOUNT]['total']);

        Database::sql('UPDATE user_voucher_cart SET voucher_id = ? WHERE user_id = ?', [$second->id, $userId]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::userVoucherCarts,
            (string) $userId,
            [UserVoucherCart::voucher_id => $second->id],
            previous: [UserVoucherCart::voucher_id => $first->id],
        ))));
        $cart = $this->cartsByConnection([self::ACCOUNT])[self::ACCOUNT];
        self::assertSame(['code' => 'CARTPAGE50', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('6000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $first->actions->setActive(false))));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertSame(['code' => 'CARTPAGE25', 'active' => false, 'discount' => '0'], $cart['voucher']);

        Database::sql('UPDATE session_voucher_cart SET voucher_id = ? WHERE session_id = ?', [$second->id, $otherSessionId]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::sessionVoucherCarts,
            (string)$otherSessionId,
            [SessionVoucherCart::voucher_id => $second->id],
            previous: [SessionVoucherCart::voucher_id => $first->id],
        ))));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertSame(['code' => 'CARTPAGE50', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('6000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(self::guestVoucherRemoved($otherSessionId)));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertNull($cart['voucher']);
        self::assertSame('12000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(self::accountVoucherRemoved($userId)));
        $cart = $this->cartsByConnection([self::ACCOUNT])[self::ACCOUNT];
        self::assertNull($cart['voucher']);
        self::assertSame('12000', $cart['total']);

        self::guestVoucher($sessionId, $second->id);
        Database::sql('DELETE FROM session_voucher_cart');
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncClearedMessageDTO(
            new DbSyncClearedSignalData(EcommerceShopDbContext::sessionVoucherCarts),
        )));
        foreach ($this->cartsByConnection([self::FIRST, self::SECOND]) as $cart) {
            self::assertNull($cart['voucher']);
            self::assertSame('12000', $cart['total']);
        }
    }

    /** A catalog clear answers only the carts holding a voucher; a binding clear answers every cart of its kind, binding already gone. */
    public function testVoucherClearsAnswerTheirCarts(): void
    {
        $otherSessionId = self::storedSession();
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 5);
        $userId = self::person();
        $voucher = $this->voucher('CARTPAGE25', 25);
        self::guestLine($sessionId, $product, 1);
        self::guestVoucher($sessionId, $voucher->id);
        self::guestLine($otherSessionId, $product, 1);
        self::accountLine($userId, $product, 1);
        self::accountVoucher($userId, $voucher->id);
        $guest = new GuestCartsLibraryAgent();
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe($guest, self::FIRST);
        self::assertSame('9000', $this->cart(self::FIRST)['total']);
        self::connect(self::SECOND, null, $otherSessionId);
        $this->subscribe($guest, self::SECOND);
        self::assertNull($this->cart(self::SECOND)['voucher']);
        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        self::assertSame('9000', $this->cart(self::ACCOUNT)['total']);

        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncClearedMessageDTO(
            new DbSyncClearedSignalData(EcommerceShopDbContext::vouchers),
        )));
        foreach ($this->cartsByConnection([self::FIRST, self::ACCOUNT]) as $cart) {
            self::assertSame(['code' => 'CARTPAGE25', 'active' => true, 'discount' => '3000'], $cart['voucher']);
            self::assertSame('9000', $cart['total']);
        }

        Database::sql('DELETE FROM user_voucher_cart');
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncClearedMessageDTO(
            new DbSyncClearedSignalData(EcommerceShopDbContext::userVoucherCarts),
        )));
        $cart = $this->cartsByConnection([self::ACCOUNT])[self::ACCOUNT];
        self::assertNull($cart['voucher']);
        self::assertSame('12000', $cart['total']);
    }

    /** A voucher fact whose answer fails leaves the usual debt, and the next voucher fact pays it with the whole cart. */
    public function testFailedVoucherAnswerRecoversOnItsNextFact(): void
    {
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        self::guestLine($sessionId, $product, 2);
        $voucher = $this->voucher('CARTPAGE25', 25);
        self::guestVoucher($sessionId, $voucher->id);
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);
        self::assertSame('18000', $this->cart(self::FIRST)['total']);

        self::assertSame([], $this->flushWithStockReadFailure(false, ...$this->voucherFacts(
            static fn () => $voucher->actions->setActive(false),
        )));
        $error = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $error->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $voucher->actions->setActive(true))));
        $cart = $this->cart(self::FIRST);
        self::assertSame(['code' => 'CARTPAGE25', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('18000', $cart['total']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
    }

    /**
     * @param string $acceptKey Connection to identify
     * @param ?int $userId Account behind it, or null for a guest
     * @param ?int $sessionId Session carried by the RT row
     */
    private static function connect(string $acceptKey, ?int $userId, ?int $sessionId): void
    {
        $token = $userId === null && $sessionId !== null ? self::guestSessionToken($sessionId) : null;
        ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey, $userId, $sessionId, $token): void {
            Hilos::$rt->connections->actions->register($acceptKey, $userId, $token, $sessionId);
        });
    }

    /**
     * @param int $sessionId Session ID used by already-addressed fixtures
     * @return string Token of the stored guest
     */
    private static function guestSessionToken(int $sessionId): string
    {
        $token = Database::sql('SELECT token FROM hilos_session WHERE id = ?', [$sessionId])->field('token');
        if (is_string($token)) {
            return $token;
        }

        $token = SessionToken::mint();
        $now = TimeHelper::nowMs();
        Database::sql('INSERT INTO hilos_session (id, token, created_at, last_seen_at) VALUES (?, ?, ?, ?)', [$sessionId, $token, $now, $now]);

        return $token;
    }

    /** @return int Fixture account ID */
    private static function person(): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Cart shopper']);

        return Database::lastInsertId();
    }

    /**
     * @param ?int $userId Account bound in storage, or null for a guest
     * @return int Persisted session ID
     */
    private static function storedSession(?int $userId = null): int
    {
        Database::sql(
            'INSERT INTO hilos_session (token, created_at, last_seen_at, user_id) VALUES (?, ?, ?, ?)',
            [SessionToken::mint(), 1000, 1000, $userId],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $sessionId Saved guest session
     * @param int $cartEpoch Active epoch expected in the key
     * @return string Expected opaque key of its active cart
     */
    private static function expectedGuestCartKey(int $sessionId, int $cartEpoch = 0): string
    {
        return hash_hmac('sha256', "ecommerce-shop:guest-cart:v1:$sessionId:$cartEpoch", Hilos::$db->sessions[$sessionId]->token);
    }

    /**
     * @param int $sessionId Guest session holding the line
     * @param int $productId Product in the cart
     * @param int $quantity Saved selection
     * @param int $cartEpoch Cart generation holding the selection
     * @return int Inserted row id
     */
    private static function guestLine(int $sessionId, int $productId, int $quantity, int $cartEpoch = 0): int
    {
        Database::sql(
            'INSERT INTO session_product_cart (session_id, cart_epoch, product_id, quantity) VALUES (?, ?, ?, ?)',
            [$sessionId, $cartEpoch, $productId, $quantity],
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

    /**
     * @param int $sessionId Guest session whose cart holds the voucher
     * @param int $voucherId Selected catalog voucher
     */
    private static function guestVoucher(int $sessionId, int $voucherId): void
    {
        Database::sql('INSERT INTO session_voucher_cart (session_id, voucher_id) VALUES (?, ?)', [$sessionId, $voucherId]);
    }

    /**
     * @param int $userId Account whose cart holds the voucher
     * @param int $voucherId Selected catalog voucher
     */
    private static function accountVoucher(int $userId, int $voucherId): void
    {
        Database::sql('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $voucherId]);
    }

    /**
     * @param int $sessionId Guest session whose voucher is taken off
     * @return WorkerDbSyncDeletedMessageDTO Removal as the cart owner announces it
     */
    private static function guestVoucherRemoved(int $sessionId): WorkerDbSyncDeletedMessageDTO
    {
        Database::sql('DELETE FROM session_voucher_cart WHERE session_id = ?', [$sessionId]);

        return new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(EcommerceShopDbContext::sessionVoucherCarts, (string) $sessionId));
    }

    /**
     * @param int $userId Account whose voucher is taken off
     * @return WorkerDbSyncDeletedMessageDTO Removal as the cart owner announces it
     */
    private static function accountVoucherRemoved(int $userId): WorkerDbSyncDeletedMessageDTO
    {
        Database::sql('DELETE FROM user_voucher_cart WHERE user_id = ?', [$userId]);

        return new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(EcommerceShopDbContext::userVoucherCarts, (string) $userId));
    }

    /**
     * @param string $code Voucher code
     * @param int $discountPercent Whole-percent discount
     * @return Voucher Active voucher written by the catalog owner
     */
    private function voucher(string $code, int $discountPercent): Voucher
    {
        $voucher = null;
        $this->voucherFacts(static function () use ($code, $discountPercent, &$voucher): void {
            $voucher = Hilos::$db->vouchers->actions->create($code, $discountPercent, true);
        });

        return $voucher ?? self::fail('The voucher was not written');
    }

    /**
     * Runs a write of the vouchers library and hands back what its ORM announced, as the daemon would deliver it.
     *
     * @param Closure(): mixed $write Catalog write under the library's claim
     * @return list<WorkerDTO> DB facts for this worker
     */
    private function voucherFacts(Closure $write): array
    {
        $router = Hilos::$sr;
        $announcements = new class extends SignalRouter {
            /** @var list<DbSyncSignalDataInterface> Facts scheduled by the voucher ORM */
            public array $facts = [];

            /**
             * @param string $signalName DB signal name
             * @param DbSyncSignalDataInterface $signalData ORM announcement
             * @param ?int $connectionIndex Connection holding the write
             */
            public function queueDbSyncSignal(string $signalName, DbSyncSignalDataInterface $signalData, ?int $connectionIndex = null): void
            {
                $this->facts[] = $signalData;
            }
        };
        $library = new VouchersLibraryAgent();
        Hilos::$sr = $announcements;
        try {
            OwnershipDeclaration::claimAll($library);
            ExecutionContext::run(new ExecutionFrame(agentId: $library->getId()), $write);
        } finally {
            Hilos::$sr = $router;
            TruthSourceRegistry::unregisterAgent($library->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($library->getId()));
        }

        $messages = [];
        foreach ($announcements->facts as $fact) {
            if ($fact->collectionKey === EcommerceShopDbContext::vouchers) {
                $messages[] = match (true) {
                    $fact instanceof DbSyncCreatedSignalData => new WorkerDbSyncCreatedMessageDTO(DbSyncCreatedSignalData::fromJson($fact->toJson())),
                    $fact instanceof DbSyncUpdatedSignalData => new WorkerDbSyncUpdatedMessageDTO(DbSyncUpdatedSignalData::fromJson($fact->toJson())),
                    default => self::fail('The voucher writer announced an unexpected ' . $fact::class),
                };
            }
        }

        return $messages;
    }

    /**
     * @param list<string> $acceptKeys Connections that each expect exactly one full cart from this flush
     * @return array<string, array<string, mixed>> The wire cart of each connection
     */
    private function cartsByConnection(array $acceptKeys): array
    {
        $carts = [];
        foreach ($this->takePageFrames() as $frame) {
            self::assertInstanceOf(PageResponseSignalData::class, $frame->data);
            self::assertArrayNotHasKey($frame->targetAcceptKey, $carts);
            $wire = PageResponseSignalData::fromArray($frame->data->toArray())->payload->data;
            $carts[$frame->targetAcceptKey] = $wire[CartPage::DATA_CART];
        }
        self::assertEqualsCanonicalizing($acceptKeys, array_keys($carts));

        return $carts;
    }

    /** @param ?int $userId New identity on the existing connection */
    private static function bindFirstUser(?int $userId): void
    {
        $sessionId = Hilos::$rt->connections[self::FIRST]->sessionId;
        Database::sql('UPDATE hilos_session SET user_id = ? WHERE id = ?', [$userId, $sessionId]);
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
        return $this->flushBeforeStockRead(static function (): never {
            throw new DatabaseException('The cart stock read failed');
        }, $duringMembership, ...$facts);
    }

    /**
     * @param Closure(): void $beforeRead Work to run once before the stock lookup
     * @param bool $duringMembership Whether to intercept membership instead of snapshot assembly
     * @param WorkerDTO ...$facts Worker messages leading to the stock read
     * @return list<ContainedFailure> Failures recorded by the browser subscription trap
     */
    private function flushBeforeStockRead(Closure $beforeRead, bool $duringMembership, WorkerDTO ...$facts): array
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $db = Hilos::$db;
        $original = $views->getValue($db);
        $beforeReadOnce = static function () use ($views, $db, $original, $beforeRead): void {
            $views->setValue($db, $original);
            $beforeRead();
        };
        $stockView = new class ($original[EcommerceShopDbContext::productWarehouseStocks], $duringMembership, $beforeReadOnce) extends DbCollection {
            public function __construct(
                private readonly ProductWarehouseStocks $original,
                private readonly bool $duringMembership,
                private readonly Closure $beforeRead,
            ) {
            }

            public function productIdAtStockKey(int $stockId): ?int
            {
                if ($this->duringMembership) {
                    ($this->beforeRead)();
                }

                return $this->original->productIdAtStockKey($stockId);
            }

            public function totalsOfProducts(int ...$productIds): ProductStockTotalsCollection
            {
                if (!$this->duringMembership) {
                    ($this->beforeRead)();
                }

                return $this->original->totalsOfProducts(...$productIds);
            }
        };
        $views->setValue($db, array_replace($original, [EcommerceShopDbContext::productWarehouseStocks => $stockView]));
        try {
            return $this->flushCartFacts(...$facts);
        } finally {
            $views->setValue($db, $original);
        }
    }

    /**
     * @param string $acceptKey Acting connection
     * @param int $lineId Saved cart row
     * @param string $ownerKind Cart kind the browser saw
     * @return SignalDTO Incoming WebSocket action
     */
    private static function removeLineSignal(string $acceptKey, int $lineId, string $ownerKind): SignalDTO
    {
        $action = EcommerceShopSignalConstants::CART_REMOVE_LINE;

        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName($action),
            new WebSocketActionSignalDTO($acceptKey, $action, ['lineId' => $lineId, 'ownerKind' => $ownerKind], 'remove-' . $lineId),
        );
    }

    /**
     * @param AgentInterface $owner Agent serving the actual subscription
     * @param string $acceptKey Acting connection
     * @param int $lineId Saved cart row
     * @param string $ownerKind Cart kind the browser saw
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Tracked action acknowledgement
     */
    private function removeLine(
        AgentInterface $owner,
        string $acceptKey,
        int $lineId,
        string $ownerKind,
    ): PageActionSuccessSignalData|PageActionErrorSignalData {
        $signal = self::removeLineSignal($acceptKey, $lineId, $ownerKind);
        self::assertEquals([new AgentDestination($owner->getType(), $owner->getIndex())], Hilos::$sr->getDestinations($signal));
        $this->deliverCartWorkerMessage(new DaemonAgentMessageDTO($owner->getId(), $signal));
        $replies = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(SignalTypeConstants::DB_SYNC_DELETED, $queued->signalType->getType());
            self::assertNotSame(EcommerceShopSignalConstants::CART_STATE, $queued->signalName->getName());
            $frame = $queued->data;
            if (!$frame instanceof WebSocketSignalData) {
                continue;
            }
            self::assertNotInstanceOf(PageResponseSignalData::class, $frame->data);
            if ($frame->data instanceof PageActionSuccessSignalData || $frame->data instanceof PageActionErrorSignalData) {
                self::assertSame($acceptKey, $frame->targetAcceptKey);
                $replies[] = $frame->data;
            }
        }
        self::assertCount(1, $replies);
        self::assertSame('remove-' . $lineId, $replies[0]->requestId);
        self::assertSame(EcommerceShopSignalConstants::CART_REMOVE_LINE, $replies[0]->action);

        return $replies[0];
    }

    /**
     * @param AgentInterface $owner Agent addressed by the current subscription
     * @param int $lineId Requested row
     * @param string $ownerKind Cart kind the browser saw
     */
    private function assertRemovalRefused(AgentInterface $owner, int $lineId, string $ownerKind): void
    {
        $reply = $this->removeLine($owner, self::FIRST, $lineId, $ownerKind);
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
        self::assertSame('This cart item is no longer available.', $reply->reason);
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
     * @return array{
     *     userId: ?int, guestCartKey: ?string, lines: list<array<string, mixed>>, itemsTotal: string,
     *     voucher: ?array{code: string, active: bool, discount: string}, total: string
     * } The wire cart
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
