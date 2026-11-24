<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Runtime\RtStaleness;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Delivers each hop through the shop worker and the wire DTOs. Cart publication requires a real commit,
 * so the case removes its own fixture rows after the assertions.
 */
final class ProductCartActionTest extends IntegrationTestCase
{
    private const string CLICKER = 'cart-add-clicker';
    private const string OTHER_TAB = 'cart-add-other';
    private const string CONNECTIONS_OWNER = 'cart-add-connections';
    private const string UNIQUE_SLUG = 'morning-meadow';
    private const string STOCK_SLUG = 'small-bunch-of-chamomile';

    private EcommerceShopWorkerManager $worker;
    private AgentManager $agents;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?GuestCartsLibraryAgent $guestOwner = null;
    private ?int $voucherId = null;

    /** @var list<string> Product agents started by this worker */
    private array $productAgentIds = [];

    /** @var list<int> Persisted fixture sessions */
    private array $sessionIds = [];

    /** @var list<int> Persisted fixture people */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        parent::setUp();
        Hilos::$db->forgetHoldingForTest(
            EcommerceShopDbContext::products,
            EcommerceShopDbContext::productSlugs,
            EcommerceShopDbContext::productWarehouseStocks,
            EcommerceShopDbContext::sessionProductCarts,
            EcommerceShopDbContext::userProductCarts,
        );
        Hilos::initBrowser();
        $this->worker = new EcommerceShopWorkerManager(1);
        Hilos::$browser->bindPageResender($this->worker);
        $this->agents = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($this->worker);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->productAgentIds as $agentId) {
                ExecutionContext::run(new ExecutionFrame(), fn () => $this->worker->handleDaemonMessage(new AgentStopDTO($agentId)));
            }
            ExecutionContext::run(new ExecutionFrame(agentId: self::CONNECTIONS_OWNER), static function (): void {
                foreach ([self::CLICKER, self::OTHER_TAB] as $acceptKey) {
                    Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
                }
            });
            foreach ($this->sessionIds as $sessionId) {
                Database::sqlRun('DELETE FROM session_product_cart WHERE session_id = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM hilos_session WHERE id = ?', [$sessionId]);
            }
            foreach ($this->userIds as $userId) {
                Database::sqlRun('DELETE FROM user_product_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM user_voucher_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_user WHERE id = ?', [$userId]);
            }
            if ($this->voucherId !== null) {
                Database::sqlRun('DELETE FROM voucher WHERE id = ?', [$this->voucherId]);
            }
            Hilos::$db->forgetLoadedDataForTest();
            Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::sessionProductCarts, EcommerceShopDbContext::userProductCarts);
        } finally {
            if ($this->guestOwner !== null) {
                TruthSourceRegistry::unregisterAgent($this->guestOwner->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->guestOwner->getId()));
            }
            RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
            Hilos::$sr = $this->previousRouter;
            Hilos::$browser = $this->previousBrowser;
            parent::tearDown();
        }
    }

    public function testGuestCanRepeatAddingAUniquePieceAndKeepsOneLineAndTheVoucher(): void
    {
        $sessionId = $this->session();
        $otherSessionId = $this->session();
        $this->connect(self::CLICKER, $sessionId);
        $this->connect(self::OTHER_TAB, $otherSessionId);
        $productId = $this->subscribe(self::CLICKER, self::UNIQUE_SLUG);
        $otherProductId = $this->subscribe(self::OTHER_TAB, self::STOCK_SLUG);
        $voucherId = $this->voucher($sessionId);
        Database::sqlRun('INSERT INTO session_voucher_cart (session_id, voucher_id) VALUES (?, ?)', [$sessionId, $voucherId]);
        Database::sqlRun('INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, 2)', [$otherSessionId, $otherProductId]);
        $guestOwner = $this->guestOwner();
        $this->drainSignals();

        foreach (['first-add' => 1, 'repeat-add' => 0] as $requestId => $writes) {
            $ask = $this->submit($productId, $requestId);
            self::assertInstanceOf(CartAddProductSignalData::class, $ask->data->data);
            self::assertSame($sessionId, $ask->data->data->sessionId);
            self::assertNull($ask->data->data->userId);
            [$cartStates, $reply, $facts] = $this->complete($ask, $guestOwner);

            self::assertSame([self::cartState(CartGroup::forSession($sessionId), null, $productId, 1, $voucherId)], $cartStates);
            $this->assertSuccess($reply, $requestId);
            self::assertSame([1], self::guestQuantities($sessionId));
            self::assertSame([2], self::guestQuantities($otherSessionId));
            self::assertCount($writes, $facts);
            if ($writes > 0) {
                $this->assertOrigin($facts[0], $ask, $guestOwner, $requestId);
            }
        }
    }

    public function testAccountAddsOneUnitWithoutReservingStockAndReceivesTheReplyAfterNavigation(): void
    {
        $userId = $this->person();
        $otherUserId = $this->person();
        $sessionId = $this->session($userId);
        $this->connect(self::CLICKER, $sessionId, $userId);
        $this->connect(self::OTHER_TAB, $this->session($otherUserId), $otherUserId);
        $productId = $this->subscribe(self::CLICKER, self::STOCK_SLUG);
        $this->subscribe(self::OTHER_TAB, self::STOCK_SLUG, [self::CLICKER => [self::STOCK_SLUG, 1]]);
        $voucherId = $this->voucher($sessionId);
        Database::sqlRun('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $voucherId]);
        Database::sqlRun('INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, 2), (?, ?, 5)', [
            $userId, $productId, $otherUserId, $productId,
        ]);
        $owner = $this->personAgent($userId);
        $this->drainSignals();
        $stock = self::stock($productId);

        $ask = $this->submit($productId, 'add-one');
        self::assertInstanceOf(CartAddProductSignalData::class, $ask->data->data);
        self::assertSame($sessionId, $ask->data->data->sessionId);
        self::assertSame($userId, $ask->data->data->userId);
        [$cartStates, $reply, $facts] = $this->complete(
            $ask,
            $owner,
            fn () => $this->subscribe(self::CLICKER, self::UNIQUE_SLUG, [self::OTHER_TAB => [self::STOCK_SLUG, 0]]),
        );

        self::assertSame([self::cartState(CartGroup::forUser($userId), $userId, $productId, 3, $voucherId)], $cartStates);
        $this->assertSuccess($reply, 'add-one');
        self::assertSame([3], self::accountQuantities($userId));
        self::assertSame([5], self::accountQuantities($otherUserId));
        self::assertSame($stock, self::stock($productId));
        self::assertCount(1, $facts);
        $this->assertOrigin($facts[0], $ask, $owner, 'add-one');
    }

    public function testDelayedAddIsRefusedUntilTheFailedCardIsDeliveredAgain(): void
    {
        $sessionId = $this->session();
        $this->connect(self::CLICKER, $sessionId);
        $productId = $this->subscribe(self::CLICKER, self::UNIQUE_SLUG);
        $guestOwner = $this->guestOwner();
        $this->drainSignals();
        $resource = Hilos::$sr->pageResource(self::CLICKER);
        self::assertSame((string)$productId, $resource?->idString);
        $delayedClick = $this->clickFrame($productId, 'add-after-delivery-failure');

        Hilos::$browser->publishPageData(
            ProductPage::PAGE,
            self::CLICKER,
            static fn (): never => throw new DatabaseException('Product read failed during live delivery'),
            [],
        );
        $refusals = $this->browserReplies(SignalConstants::SUBSCRIPTION_PAGE_ERROR);
        self::assertCount(1, $refusals);
        self::assertSame(self::CLICKER, $refusals[0]->targetAcceptKey);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusals[0]->data);
        self::assertSame(ProductPage::PAGE, $refusals[0]->data->page);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $refusals[0]->data->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::CLICKER));
        self::assertSame($resource, Hilos::$sr->pageResource(self::CLICKER));

        $agent = $this->startProduct(self::UNIQUE_SLUG);
        self::assertSame($productId, $agent->reading()?->product->id);
        self::assertEquals([new AgentDestination($agent->getType(), $agent->getIndex())], Hilos::$sr->getDestinations($delayedClick));
        $this->deliver($agent, $delayedClick);
        $this->assertFailure($this->onlyReply(), 'add-after-delivery-failure', 'This product is no longer available on this page.');
        self::assertSame([], self::guestQuantities($sessionId));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::CLICKER));
        self::assertSame($resource, Hilos::$sr->pageResource(self::CLICKER));

        self::assertSame(PageResendOutcome::Answered, $this->worker->resendPage(
            ProductPage::PAGE,
            self::CLICKER,
            [ProductPageSubscribeParams::SLUG => self::UNIQUE_SLUG],
        ));
        self::assertSame($productId, $this->takeCard(self::CLICKER, self::UNIQUE_SLUG));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::CLICKER));

        $ask = $this->submit($productId, 'add-after-recovery');
        [$states, $reply, $facts] = $this->complete($ask, $guestOwner);
        $this->assertSuccess($reply, 'add-after-recovery');
        self::assertSame([1], self::guestQuantities($sessionId));
        self::assertSame([self::cartState(CartGroup::forSession($sessionId), null, $productId, 1, null)], $states);
        self::assertCount(1, $facts);
        $this->assertOrigin($facts[0], $ask, $guestOwner, 'add-after-recovery');
    }

    public function testAFullStockLineKeepsItsQuantityAndPublishesNoCartState(): void
    {
        $userId = $this->person();
        $this->connect(self::CLICKER, $this->session($userId), $userId);
        $productId = $this->subscribe(self::CLICKER, self::STOCK_SLUG);
        $stock = self::stock($productId);
        self::assertGreaterThan(0, $stock);
        Database::sqlRun('INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, ?)', [$userId, $productId, $stock]);
        $owner = $this->personAgent($userId);
        $this->drainSignals();

        [$cartStates, $reply, $facts] = $this->complete($this->submit($productId, 'over-stock'), $owner);

        $this->assertFailure($reply, 'over-stock', "Only {$stock} in stock");
        self::assertSame([], $cartStates);
        self::assertSame([], $facts);
        self::assertSame([$stock], self::accountQuantities($userId));
        self::assertSame($stock, self::stock($productId));
    }

    public function testStalePageAndChangedSessionAreRefusedWithoutTouchingEitherCart(): void
    {
        $sessionId = $this->session();
        $userId = $this->person();
        $replacementId = $this->person();
        $this->connect(self::CLICKER, $sessionId);
        $this->connect(self::OTHER_TAB, $this->session());
        $productId = $this->subscribe(self::CLICKER, self::UNIQUE_SLUG);
        $otherProductId = $this->subscribe(self::OTHER_TAB, self::STOCK_SLUG);
        $guestOwner = $this->guestOwner();
        $this->drainSignals();

        $this->sendClick($otherProductId, 'wrong-product');
        $this->assertFailure($this->onlyReply(), 'wrong-product', 'This product is no longer available on this page.');

        $this->connect(self::CLICKER, null);
        $this->sendClick($productId, 'no-session');
        $this->assertFailure($this->onlyReply(), 'no-session', 'Your cart is not ready. Please try again.');
        $this->connect(self::CLICKER, $sessionId);
        $this->drainSignals();

        $guestAsk = $this->submit($productId, 'guest-became-account');
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $this->drainSignals();
        [$states, $reply, $facts] = $this->complete($guestAsk, $guestOwner);
        $this->assertFailure($reply, 'guest-became-account', 'Your cart is not ready. Please try again.');
        self::assertSame([], $states);
        self::assertSame([], $facts);
        self::assertSame([], self::guestQuantities($sessionId));
        self::assertSame([], self::accountQuantities($userId));

        $this->connect(self::CLICKER, $sessionId, $userId);
        $owner = $this->personAgent($userId);
        $this->drainSignals();
        $accountAsk = $this->submit($productId, 'account-changed');
        Hilos::$db->sessions[$sessionId]->actions->bindUser($replacementId);
        $this->connect(self::CLICKER, $sessionId, $replacementId);
        $this->drainSignals();
        [$states, $reply, $facts] = $this->complete($accountAsk, $owner);
        $this->assertFailure($reply, 'account-changed', 'Your cart is not ready. Please try again.');
        self::assertSame([], $states);
        self::assertSame([], $facts);
        self::assertSame([], self::accountQuantities($userId));
        self::assertSame([], self::accountQuantities($replacementId));

        $agent = $this->startProduct(self::UNIQUE_SLUG);
        $frame = $this->clickFrame($productId, 'page-replaced-in-flight');
        $this->subscribe(self::CLICKER, self::STOCK_SLUG, [self::OTHER_TAB => [self::STOCK_SLUG, 1]]);
        $this->deliver($agent, $frame);
        $this->assertFailure($this->onlyReply(), 'page-replaced-in-flight', 'This product is no longer available on this page.');
        self::assertSame([], self::accountQuantities($replacementId));
    }

    /** @return array<string, array{bool}> Whether the stored session switches to another account */
    public static function changedAccountBindings(): array
    {
        return [
            'unbound' => [false],
            'rebound' => [true],
        ];
    }

    #[DataProvider('changedAccountBindings')]
    public function testAccountAddRefusesAChangedStoredBindingBeforeItsMirrorsCatchUp(bool $rebind): void
    {
        $userId = $this->person();
        $replacementId = $this->person();
        $sessionId = $this->session($userId);
        $this->connect(self::CLICKER, $sessionId, $userId);
        $productId = $this->subscribe(self::CLICKER, self::UNIQUE_SLUG);
        $owner = $this->personAgent($userId);
        $this->drainSignals();
        $ask = $this->submit($productId, 'binding-changed-in-flight');

        // Simulate the session writer's committed change before this worker receives either mirror update.
        Database::sqlRun('UPDATE hilos_session SET user_id = ? WHERE id = ?', [$rebind ? $replacementId : null, $sessionId]);
        self::assertSame($userId, Hilos::$db->sessions[$sessionId]->userId);
        self::assertSame($userId, Hilos::$rt->connections[self::CLICKER]->userId);

        [$states, $reply, $facts] = $this->complete($ask, $owner);

        $this->assertFailure($reply, 'binding-changed-in-flight', 'Your cart is not ready. Please try again.');
        self::assertSame([], $states);
        self::assertSame([], $facts);
        self::assertSame([], self::guestQuantities($sessionId));
        self::assertSame([], self::accountQuantities($userId));
        self::assertSame([], self::accountQuantities($replacementId));
    }

    public function testAccountAddRefusesAConnectionThatBecomesStaleDuringHandover(): void
    {
        $userId = $this->person();
        $sessionId = $this->session($userId);
        $this->connect(self::CLICKER, $sessionId, $userId);
        $productId = $this->subscribe(self::CLICKER, self::UNIQUE_SLUG);
        $owner = $this->personAgent($userId);
        $this->drainSignals();
        $ask = $this->submit($productId, 'connection-stale-in-flight');

        RtStaleness::mark(EcommerceShopRtContext::connections, [self::CLICKER], microtime(true));
        try {
            [$states, $reply, $facts] = $this->complete($ask, $owner);

            $this->assertFailure($reply, 'connection-stale-in-flight', 'Your cart is not ready. Please try again.');
            self::assertSame([], $states);
            self::assertSame([], $facts);
            self::assertSame([], self::guestQuantities($sessionId));
            self::assertSame([], self::accountQuantities($userId));
        } finally {
            RtStaleness::clear(EcommerceShopRtContext::connections, [self::CLICKER]);
        }
    }

    private function session(?int $userId = null): int
    {
        $session = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint());
        $sessionId = $session->id ?? self::fail('Session fixture has no ID');
        $this->sessionIds[] = $sessionId;
        if ($userId !== null) {
            $session->actions->bindUser($userId);
        }

        return $sessionId;
    }

    private function person(): int
    {
        $person = Hilos::$db->users->actions->createWithName('Cart action visitor');
        $userId = $person->id ?? self::fail('Account fixture has no ID');
        $this->userIds[] = $userId;

        return $userId;
    }

    private function voucher(int $sessionId): int
    {
        $code = "CARTADD-{$sessionId}";
        Database::sqlRun('INSERT INTO voucher (code, discount_percent, active) VALUES (?, ?, ?)', [$code, 10, 1]);
        $id = Database::sql('SELECT id FROM voucher WHERE code = ?', [$code])->field('id');
        self::assertNotNull($id);

        return $this->voucherId = (int)$id;
    }

    private function guestOwner(): GuestCartsLibraryAgent
    {
        $this->guestOwner = new GuestCartsLibraryAgent();
        $this->underAgent($this->guestOwner, fn () => $this->startAgent($this->guestOwner));

        return $this->guestOwner;
    }

    private function connect(string $acceptKey, ?int $sessionId, ?int $userId = null): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: self::CONNECTIONS_OWNER), static function () use ($acceptKey, $sessionId, $userId): void {
            Hilos::$rt->connections[$acceptKey]?->actions->unregister();
            Hilos::$rt->connections->actions->register($acceptKey, $userId, sessionId: $sessionId);
        });
    }

    /** @return ProductAgent Started agent at the given product address */
    private function startProduct(string $slug): ProductAgent
    {
        $id = AgentType::PRODUCT . ':' . $slug;
        if (!$this->agents->hasAgent($id)) {
            ExecutionContext::run(new ExecutionFrame(), fn () => $this->worker->handleDaemonMessage(new AgentStartDTO($id)));
            $this->productAgentIds[] = $id;
        }
        $agent = $this->agents->getAgent($id);
        self::assertInstanceOf(ProductAgent::class, $agent);

        return $agent;
    }

    /**
     * @param string $acceptKey Tab opening the card
     * @param string $slug Product address
     * @param array<string, array{string, int}> $viewerUpdates Expected slug and count by tab
     * @return int Primary key read from the accepted product card
     */
    private function subscribe(string $acceptKey, string $slug, array $viewerUpdates = []): int
    {
        $agent = $this->startProduct($slug);
        $this->drainSignals();
        $previous = Hilos::$sr->pageSubscription($acceptKey);
        if ($previous?->agentIndex !== null && $previous->agentIndex !== $slug) {
            $this->deliver($this->startProduct($previous->agentIndex), new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                new SignalName(ProductPage::PAGE),
                new WebSocketPageUnsubscribeSignalDTO($acceptKey),
            ));
        }
        $this->deliver($agent, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(ProductPage::PAGE),
            new WebSocketPageSubscribeSignalDTO($acceptKey, ProductPage::PAGE, [ProductPageSubscribeParams::SLUG => $slug]),
        ));

        return $this->takeCard($acceptKey, $slug, $viewerUpdates);
    }

    /**
     * @param string $acceptKey Tab receiving the full card
     * @param string $slug Product address
     * @param array<string, array{string, int}> $viewerUpdates Expected slug and count by tab
     * @return int Primary key carried by the only full card
     */
    private function takeCard(string $acceptKey, string $slug, array $viewerUpdates = []): int
    {
        $responses = [];
        foreach ($this->browserReplies(SignalTypeConstants::PAGE_RESPONSE) as $response) {
            self::assertInstanceOf(PageResponseSignalData::class, $response->data);
            self::assertSame(ProductPage::PAGE, $response->data->pageKey);
            $target = $response->targetAcceptKey;
            $payload = $response->data->payload;
            if (array_key_exists(ProductPage::DATA_PRODUCT, $payload->data)) {
                self::assertSame($acceptKey, $target);
                self::assertSame($slug, $payload->data[ProductPage::DATA_PRODUCT][EntityProduct::slug]);
                self::assertSame($slug, $payload->data[ProductPage::DATA_PRODUCT_VIEWERS][EntityProduct::slug]);
                $responses[] = $payload->data[ProductPage::DATA_PRODUCT][EntityProduct::id];
                continue;
            }

            self::assertArrayHasKey($target, $viewerUpdates);
            [$viewerSlug, $count] = $viewerUpdates[$target];
            self::assertSame([PagePayload::data => [
                ProductPage::DATA_PRODUCT_VIEWERS => [EntityProduct::slug => $viewerSlug, 'otherViewerCount' => $count],
            ]], $payload->toArray());
            unset($viewerUpdates[$target]);
        }
        self::assertCount(1, $responses);
        self::assertSame([], $viewerUpdates);

        return $responses[0];
    }

    private function clickFrame(int $productId, string $requestId): SignalDTO
    {
        $action = EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART;

        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName($action),
            new WebSocketActionSignalDTO(self::CLICKER, $action, [CartStateSignalData::PRODUCT_ID => $productId], $requestId),
        );
    }

    private function sendClick(int $productId, string $requestId): void
    {
        $frame = $this->clickFrame($productId, $requestId);
        [$destination] = Hilos::$sr->getDestinations($frame);
        self::assertInstanceOf(AgentDestination::class, $destination);
        self::assertSame(ProductAgent::AGENT_TYPE, $destination->agentType);
        self::assertNotNull($destination->agentIndex);
        $this->deliver($this->startProduct($destination->agentIndex), $frame);
    }

    /** @return SignalDTO The owner request, with the page's tracked answer deferred */
    private function submit(int $productId, string $requestId): SignalDTO
    {
        $this->sendClick($productId, $requestId);
        $asks = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(WebSocketSignalData::class, $signal->data, 'The page defers the answer until the cart owner responds');
            if ($signal->data instanceof AgentSignalData) {
                $asks[] = $signal;
            }
        }
        self::assertCount(1, $asks);
        [$ask] = $asks;
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $ask->signalType->getType());
        self::assertNotNull($ask->data->receiptId);

        return $ask;
    }

    /**
     * @param SignalDTO $ask Deferred owner request
     * @param AgentInterface $owner Actual cart writer
     * @param ?Closure $beforeAnswer Optional navigation while the answer is in transit
     * @return array{list<array{?string, array<string, mixed>}>, WebSocketSignalData, list<DbSyncCreatedSignalData|DbSyncUpdatedSignalData>}
     */
    private function complete(SignalDTO $ask, AgentInterface $owner, ?Closure $beforeAnswer = null): array
    {
        self::assertEquals([new AgentDestination($owner->getType(), $owner->getIndex())], Hilos::$sr->getDestinations($ask));
        $this->deliver($owner, $ask);
        self::assertFalse(Database::hasOpenTransactions());

        $answers = [];
        $states = [];
        $facts = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData) {
                self::assertSame(EcommerceShopSignalConstants::CART_STATE, $signal->signalName->getName());
                self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
                self::assertNull($data->targetAcceptKey);
                self::assertInstanceOf(CartStateSignalData::class, $data->data);
                $states[] = [$data->targetGroup, $data->data->toArray()];
            } elseif ($data instanceof AgentSignalData) {
                self::assertSame(EcommerceShopSignalConstants::CART_PRODUCT_ADD_DONE, $signal->signalName->getName());
                self::assertInstanceOf(CartAddProductDoneSignalData::class, $data->data);
                $answers[] = $signal;
            } elseif (($data instanceof DbSyncCreatedSignalData || $data instanceof DbSyncUpdatedSignalData)
                && in_array($data->collectionKey, [EcommerceShopDbContext::sessionProductCarts, EcommerceShopDbContext::userProductCarts], true)) {
                $facts[] = $data;
            }
        }

        self::assertCount(1, $answers);
        $beforeAnswer?->__invoke();
        $origin = $this->startProduct($ask->signalSource->getIndex() ?? self::fail('The ask must come from an indexed product'));
        self::assertEquals([new AgentDestination($origin->getType(), $origin->getIndex())], Hilos::$sr->getDestinations($answers[0]));
        $this->deliver($origin, $answers[0]);

        return [$states, $this->onlyReply(), $facts];
    }

    private function deliver(AgentInterface $agent, SignalDTO $signal): void
    {
        $id = $agent->getId();
        if (!$this->agents->hasAgent($id)) {
            $this->agents->addAgent($id, $agent);
        }

        $message = new DaemonAgentMessageDTO($id, $signal);
        ExecutionContext::run(
            new ExecutionFrame(),
            fn () => $this->worker->handleDaemonMessage(DaemonAgentMessageDTO::fromArray($message->toArray())),
        );
    }

    private function onlyReply(): WebSocketSignalData
    {
        $replies = $this->browserReplies();
        self::assertCount(1, $replies);
        self::assertSame(self::CLICKER, $replies[0]->targetAcceptKey);

        return $replies[0];
    }

    /**
     * @param ?string $name Expected name when collecting a single kind of browser frame
     * @return list<WebSocketSignalData> Addressed browser frames queued without a cart handover or write
     */
    private function browserReplies(?string $name = null): array
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(AgentSignalData::class, $signal->data, 'Unexpected agent handover');
            self::assertNotInstanceOf(DbSyncClearedSignalData::class, $signal->data, 'Unexpected database write');
            if ($signal->data instanceof DbSyncSignalDataInterface) {
                // The action dispatcher opens a journal receipt even for a refused action.
                self::assertSame(HilosDbContext::changeLogReceipts, $signal->data->collectionKey, 'Unexpected database write');
            }
            if ($signal->data instanceof WebSocketSignalData && $signal->signalName->getName() !== SignalTypeConstants::RT_STALENESS) {
                self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
                if ($name !== null) {
                    self::assertSame($name, $signal->signalName->getName());
                }
                $replies[] = $signal->data;
            }
        }

        return $replies;
    }

    private function assertSuccess(WebSocketSignalData $reply, string $requestId): void
    {
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply->data);
        self::assertSame([
            'action' => EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART,
            'requestId' => $requestId,
        ], $reply->data->toArray());
    }

    private function assertFailure(WebSocketSignalData $reply, string $requestId, string $reason): void
    {
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply->data);
        self::assertSame([
            'action' => EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART,
            'reason' => $reason,
            'requestId' => $requestId,
        ], $reply->data->toArray());
    }

    private function assertOrigin(
        DbSyncCreatedSignalData|DbSyncUpdatedSignalData $fact,
        SignalDTO $ask,
        AgentInterface $owner,
        string $requestId,
    ): void {
        self::assertSame(self::CLICKER, $fact->origin);
        self::assertSame($requestId, $fact->originRequestId);
        self::assertSame($ask->data->receiptId, $fact->receiptId);
        self::assertSame($owner->getId(), $fact->writerAgentId);
    }

    /** @return array{string, array<string, mixed>} Expected group and full cart body */
    private static function cartState(string $group, ?int $userId, int $productId, int $quantity, ?int $voucherId): array
    {
        return [$group, [
            'userId' => $userId,
            'lines' => [['productId' => $productId, 'quantity' => $quantity]],
            'voucherId' => $voucherId,
        ]];
    }

    /** @return list<int> Independently read quantities for the guest's product lines */
    private static function guestQuantities(int $sessionId): array
    {
        return array_map('intval', array_column(
            Database::sql('SELECT quantity FROM session_product_cart WHERE session_id = ? ORDER BY id', [$sessionId])->rows(),
            'quantity',
        ));
    }

    /** @return list<int> Independently read quantities for the account's product lines */
    private static function accountQuantities(int $userId): array
    {
        return array_map('intval', array_column(
            Database::sql('SELECT quantity FROM user_product_cart WHERE user_id = ? ORDER BY id', [$userId])->rows(),
            'quantity',
        ));
    }

    private static function stock(int $productId): int
    {
        return (int)Database::sql('SELECT SUM(units) AS total FROM product_warehouse_stock WHERE product_id = ?', [$productId])->field('total');
    }
}
