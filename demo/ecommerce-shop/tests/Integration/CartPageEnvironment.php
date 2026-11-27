<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\SessionToken;
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
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Helpers\TimeHelper;
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
     * @param string $page Page addressed by the request
     */
    protected function deliver(
        AgentInterface $agent,
        string $type,
        SignalDataInterface $payload,
        string $source = SignalSource::DAEMON,
        array $meta = [],
        string $page = CartPage::PAGE,
    ): void {
        $this->agents->addAgent($agent->getId(), $agent);
        $message = new DaemonAgentMessageDTO($agent->getId(), new SignalDTO(
            new SignalSource($source),
            new SignalType($type),
            new SignalName($page),
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

    /**
     * @param string $acceptKey Connection to identify
     * @param ?int $userId Account behind it, or null for a guest
     * @param ?int $sessionId Session carried by the RT row
     * @param ?string $token Token carried by the RT row, resolved from the guest session when omitted
     */
    protected static function connect(string $acceptKey, ?int $userId, ?int $sessionId, ?string $token = null): void
    {
        $token ??= $userId === null && $sessionId !== null ? self::guestSessionToken($sessionId) : null;
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
    protected static function person(): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Cart shopper']);

        return Database::lastInsertId();
    }

    /**
     * @param ?int $userId Account bound in storage, or null for a guest
     * @return int Persisted session ID
     */
    protected static function storedSession(?int $userId = null): int
    {
        Database::sql(
            'INSERT INTO hilos_session (token, created_at, last_seen_at, user_id) VALUES (?, ?, ?, ?)',
            [SessionToken::mint(), 1000, 1000, $userId],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $sessionId Guest session holding the line
     * @param int $productId Product in the cart
     * @param int $quantity Saved selection
     * @param int $cartEpoch Cart generation holding the selection
     * @return int Inserted row id
     */
    protected static function guestLine(int $sessionId, int $productId, int $quantity, int $cartEpoch = 0): int
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
    protected static function accountLine(int $userId, int $productId, int $quantity): int
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
    protected static function productPriceFact(int $productId, int $price): WorkerDbSyncUpdatedMessageDTO
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
    protected static function stockUnitsFact(int $stockId, int $units): WorkerDbSyncUpdatedMessageDTO
    {
        Database::sql('UPDATE product_warehouse_stock SET units = ? WHERE id = ?', [$units, $stockId]);

        return new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::productWarehouseStocks, (string) $stockId, [EntityProductWarehouseStock::units => $units],
        ));
    }

    /**
     * @param int $userId Account whose cart holds the voucher
     * @param int $voucherId Selected catalog voucher
     */
    protected static function accountVoucher(int $userId, int $voucherId): void
    {
        Database::sql('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $voucherId]);
    }

    /**
     * @param string $code Voucher code
     * @param int $discountPercent Whole-percent discount
     * @return Voucher Active voucher written by the catalog owner
     */
    protected function voucher(string $code, int $discountPercent): Voucher
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
    protected function voucherFacts(Closure $write): array
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

    /** @param ?int $userId New identity on the existing connection */
    protected static function bindFirstUser(?int $userId): void
    {
        $sessionId = Hilos::$rt->connections[self::FIRST]->sessionId;
        Database::sql('UPDATE hilos_session SET user_id = ? WHERE id = ?', [$userId, $sessionId]);
        ExecutionContext::run(new ExecutionFrame(), static function () use ($userId): void {
            Hilos::$rt->connections[self::FIRST]->actions->bindUser($userId);
        });
    }

    /**
     * @param AgentInterface $agent Agent the subscription reached
     * @param string $acceptKey Subscribing connection
     * @param string $page Page under test
     */
    protected function subscribe(AgentInterface $agent, string $acceptKey, string $page = CartPage::PAGE): void
    {
        $this->deliver(
            $agent,
            SignalTypeConstants::PAGE_SUBSCRIBE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page),
            SignalSource::WEBSOCKET,
            page: $page,
        );
    }

    /**
     * @param AgentInterface $owner Current page owner
     * @param string $deliveryId Token confirmed by the connection's master
     * @param string $page Page under test
     */
    protected function subscribeTrackedPage(AgentInterface $owner, string $deliveryId, string $page = CartPage::PAGE): void
    {
        $request = new WebSocketPageSubscribeSignalDTO(self::FIRST, $page, deliveryId: $deliveryId);
        $identity = PageHandoffIdentity::current(self::FIRST);
        self::assertNotNull($identity);
        $this->deliver($owner, SignalTypeConstants::PAGE_SUBSCRIBE, $request, SignalSource::WEBSOCKET, $identity->toMeta(), $page);
        $probe = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(PageHandoffIdentity::PROBE, $probe?->meta[PageHandoffIdentity::META_PROOF]);
        $this->deliver($owner, SignalTypeConstants::PAGE_ACCESS_REASSESS, $request, meta: [
            ...$probe->meta,
            PageHandoffIdentity::META_PROOF => PageHandoffIdentity::GRANTED,
        ], page: $page);
        self::assertSame($deliveryId, Hilos::$sr->pageSubscription(self::FIRST)?->deliveryId);
    }

    /**
     * @param AgentInterface $previous Agent the master releases
     * @param AgentInterface $next Agent selected from the RT identity
     * @param string $page Page under test
     */
    protected function handoff(AgentInterface $previous, AgentInterface $next, string $page = CartPage::PAGE): void
    {
        $this->deliver($previous, SignalTypeConstants::PAGE_UNSUBSCRIBE, new WebSocketPageUnsubscribeSignalDTO(self::FIRST), page: $page);
        $identity = PageHandoffIdentity::current(self::FIRST);
        self::assertNotNull($identity);
        $this->deliver(
            $next, SignalTypeConstants::PAGE_ACCESS_REASSESS,
            new WebSocketPageSubscribeSignalDTO(self::FIRST, $page),
            meta: $identity->toMeta(),
            page: $page,
        );
    }

    /**
     * @param string $acceptKey Connection expecting a full cart
     * @param string $page Page under test
     * @return array{
     *     userId: ?int, guestCartKey: ?string, lines: list<array<string, mixed>>, itemsTotal: string,
     *     voucher: ?array{id: int, code: string, active: bool, discount: string}, total: string
     * } The wire cart
     */
    protected function cart(string $acceptKey, string $page = CartPage::PAGE): array
    {
        $reply = $this->pageReply($acceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame($page, $reply->pageKey);
        $wire = PageResponseSignalData::fromArray($reply->toArray())->payload->data;
        self::assertSame([CartPage::DATA_CART], array_keys($wire));

        return $wire[CartPage::DATA_CART];
    }

    /**
     * @param string $acceptKey Connection awaiting its single reply
     * @return PageResponseSignalData|PageSubscriptionErrorSignalData Page reply or refusal
     */
    protected function pageReply(string $acceptKey): PageResponseSignalData|PageSubscriptionErrorSignalData
    {
        $frames = $this->takePageFrames();
        self::assertCount(1, $frames);
        self::assertSame($acceptKey, $frames[0]->targetAcceptKey);

        return $frames[0]->data;
    }

    /** @return list<WebSocketSignalData> Page frames queued for every connection */
    protected function takePageFrames(): array
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
