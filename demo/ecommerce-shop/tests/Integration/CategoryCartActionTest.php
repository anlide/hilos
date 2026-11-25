<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CategoryPage;
use Demo\EcommerceShop\Pages\DTO\CategoryPageSubscribeParams;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/** Runs the category-to-cart handover through the worker. Cart publication requires a real commit. */
final class CategoryCartActionTest extends IntegrationTestCase
{
    private const string CLICKER = 'category-cart-clicker';
    private const string NEIGHBOR = 'category-cart-neighbor';
    private const string CONNECTIONS_OWNER = 'category-cart-connections';
    private const string CATEGORY = 'bouquets';
    private const string OTHER_CATEGORY = 'potted-plants';
    private const string UNIQUE_SLUG = 'morning-meadow';
    private const string STOCK_SLUG = 'small-bunch-of-chamomile';
    private const string FOREIGN_SLUG = 'monstera-deliciosa-80-cm';

    private EcommerceShopWorkerManager $worker;
    private CategoriesLibraryAgent $categories;
    private ?GuestCartsLibraryAgent $guest = null;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?int $voucherId = null;

    /** @var list<int> Persisted fixture sessions */
    private array $sessionIds = [];

    /** @var list<int> Persisted fixture accounts */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        parent::setUp();
        Hilos::$db->forgetHoldingForTest(
            EcommerceShopDbContext::categories,
            EcommerceShopDbContext::categorySlugs,
            EcommerceShopDbContext::products,
            EcommerceShopDbContext::productWarehouseStocks,
            EcommerceShopDbContext::sessionProductCarts,
            EcommerceShopDbContext::userProductCarts,
        );
        Hilos::initBrowser();
        $this->worker = new EcommerceShopWorkerManager(1);
        $this->categories = new CategoriesLibraryAgent();
        $this->underAgent($this->categories, fn () => $this->startAgent($this->categories));
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
    }

    protected function tearDown(): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: self::CONNECTIONS_OWNER), static function (): void {
                foreach ([self::CLICKER, self::NEIGHBOR] as $acceptKey) {
                    Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
                }
            });
            foreach ($this->sessionIds as $sessionId) {
                Database::sqlRun('DELETE FROM session_product_cart WHERE session_id = ?', [$sessionId]);
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
        } finally {
            foreach ([$this->categories ?? null, $this->guest] as $agent) {
                if ($agent !== null) {
                    TruthSourceRegistry::unregisterAgent($agent->getId());
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
                }
            }
            RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
            Hilos::$db->forgetLoadedDataForTest();
            Hilos::$db->forgetHoldingForTest(
                EcommerceShopDbContext::categories,
                EcommerceShopDbContext::categorySlugs,
                EcommerceShopDbContext::productWarehouseStocks,
                EcommerceShopDbContext::sessionProductCarts,
                EcommerceShopDbContext::userProductCarts,
            );
            Hilos::$sr = $this->previousRouter;
            Hilos::$browser = $this->previousBrowser;
            parent::tearDown();
        }
    }

    public function testGuestCanAddTheSameUniquePieceTwiceAndKeepsOne(): void
    {
        $sessionId = $this->connect(self::CLICKER);
        $this->guest = new GuestCartsLibraryAgent();
        $this->underAgent($this->guest, fn () => $this->startAgent($this->guest));
        $this->subscribe(self::CLICKER, self::CATEGORY);
        $productId = self::productId(self::UNIQUE_SLUG);

        foreach (['add-unique', 'repeat-unique'] as $requestId) {
            $this->click($productId, $requestId);
            $states = $this->completeAdd($this->guest, $productId, self::UNIQUE_SLUG, $sessionId, null, $requestId);

            self::assertSame([[CartGroup::forSession($sessionId, 0), self::cartBody(null, $productId, 1, null)]], $states);
            $this->assertReply($requestId);
            self::assertSame([1], self::guestQuantities($sessionId));
        }
    }

    public function testAccountAddsOneUnitAndKeepsItsVoucherAndOriginalReplyAfterNavigation(): void
    {
        $userId = $this->person();
        $neighborId = $this->person();
        $sessionId = $this->connect(self::CLICKER, $userId);
        $this->connect(self::NEIGHBOR, $neighborId);
        $productId = self::productId(self::STOCK_SLUG);
        $code = "CATEGORY-CART-{$sessionId}";
        Database::sqlRun('INSERT INTO voucher (code, discount_percent, active) VALUES (?, 10, 1)', [$code]);
        $voucherId = Database::sql('SELECT id FROM voucher WHERE code = ?', [$code])->field('id');
        self::assertNotNull($voucherId);
        $this->voucherId = (int) $voucherId;
        Database::sqlRun('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $this->voucherId]);
        Database::sqlRun('INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, 2), (?, ?, 5)', [
            $userId, $productId, $neighborId, $productId,
        ]);
        $owner = $this->personAgent($userId);
        $this->subscribe(self::CLICKER, self::CATEGORY);
        $this->subscribe(self::NEIGHBOR, self::CATEGORY);

        $this->click($productId, 'add-one');
        $states = $this->completeAdd(
            $owner,
            $productId,
            self::STOCK_SLUG,
            $sessionId,
            $userId,
            'add-one',
            fn () => $this->subscribe(self::CLICKER, self::OTHER_CATEGORY),
        );

        self::assertSame([[CartGroup::forUser($userId), self::cartBody($userId, $productId, 3, $this->voucherId)]], $states);
        $this->assertReply('add-one');
        self::assertSame([3], self::accountQuantities($userId));
        self::assertSame([5], self::accountQuantities($neighborId));
    }

    public function testAnotherCategoryAndAnUnacceptedAddressAreRefusedBeforeHandover(): void
    {
        $sessionId = $this->connect(self::CLICKER);
        $this->subscribe(self::CLICKER, self::CATEGORY);
        $reason = 'This product is no longer available in this category.';

        $this->click(self::productId(self::FOREIGN_SLUG), 'foreign-product');
        $this->assertReply('foreign-product', $reason);

        // The last card is still bound while the subscription has a different address.
        $resource = Hilos::$sr->pageResource(self::CLICKER);
        Hilos::$sr->updatePageSubscription(CategoryPage::PAGE, new WebSocketPageUpdateSubscriptionSignalDTO(
            self::CLICKER,
            CategoryPage::PAGE,
            [CategoryPageSubscribeParams::SLUG => self::CATEGORY . ' '],
        ));
        self::assertSame($resource, Hilos::$sr->pageResource(self::CLICKER));
        $this->click(self::productId(self::UNIQUE_SLUG), 'stale-address');
        $this->assertReply('stale-address', $reason);

        self::assertSame([], self::guestQuantities($sessionId));
    }

    public function testAStoredCategoryMoveIsRefusedBeforeHandoverWhileThePageStillHoldsTheProduct(): void
    {
        $sessionId = $this->connect(self::CLICKER);
        $this->subscribe(self::CLICKER, self::CATEGORY);
        $productId = self::productId(self::UNIQUE_SLUG);
        $category = Hilos::$db->categories->whereColumnIs(EntityCategory::slug, self::CATEGORY)->first();
        $otherCategory = Hilos::$db->categories->whereColumnIs(EntityCategory::slug, self::OTHER_CATEGORY)->first();
        self::assertNotNull($category);
        self::assertNotNull($otherCategory);

        // A committed move is visible in storage before the page worker receives its sync.
        Database::sqlRun('UPDATE product SET category_id = ? WHERE id = ?', [$otherCategory->id, $productId]);
        try {
            self::assertSame($category->id, Hilos::$db->products[$productId]->categoryId);
            $this->click($productId, 'add-after-stored-move');

            $this->assertReply('add-after-stored-move', 'This product is no longer available in this category.');
            self::assertSame([], self::guestQuantities($sessionId));
        } finally {
            Database::sqlRun('UPDATE product SET category_id = ? WHERE id = ?', [$category->id, $productId]);
        }
    }

    public function testTheWriterRefusesAnAddAtTheStockLimitAndLeavesTheLineUntouched(): void
    {
        $userId = $this->person();
        $sessionId = $this->connect(self::CLICKER, $userId);
        $productId = self::productId(self::STOCK_SLUG);
        $stock = (int) Database::sql('SELECT SUM(units) AS total FROM product_warehouse_stock WHERE product_id = ?', [$productId])->field('total');
        self::assertGreaterThan(0, $stock);
        Database::sqlRun('INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, ?)', [$userId, $productId, $stock]);
        $owner = $this->personAgent($userId);
        $this->subscribe(self::CLICKER, self::CATEGORY);

        $this->click($productId, 'add-at-limit');
        $states = $this->completeAdd($owner, $productId, self::STOCK_SLUG, $sessionId, $userId, 'add-at-limit');

        self::assertSame([], $states);
        $this->assertReply('add-at-limit', "Only {$stock} in stock");
        self::assertSame([$stock], self::accountQuantities($userId));
    }

    public function testTheAccountWriterRefusesAnAddWhileItsStockMirrorStillShowsTwoUnits(): void
    {
        $userId = $this->person();
        $sessionId = $this->connect(self::CLICKER, $userId);
        $slug = 'snake-plant-40-cm';
        $productId = self::productId($slug);
        Database::sqlRun('INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, 1)', [$userId, $productId]);
        $owner = $this->personAgent($userId);
        $this->subscribe(self::CLICKER, self::OTHER_CATEGORY);
        self::assertSame(2, CartLineRules::unitsInStock($productId));

        // Keep the same stock row and leave the worker's warmed copy untouched.
        Database::sqlRun('UPDATE product_warehouse_stock SET units = 1 WHERE product_id = ?', [$productId]);
        try {
            self::assertSame(2, CartLineRules::unitsInStock($productId));
            $this->click($productId, 'add-after-stock-fell');
            $states = $this->completeAdd($owner, $productId, $slug, $sessionId, $userId, 'add-after-stock-fell');

            self::assertSame([], $states);
            $this->assertReply('add-after-stock-fell', 'Only 1 in stock');
            self::assertSame([1], self::accountQuantities($userId));
        } finally {
            Database::sqlRun('UPDATE product_warehouse_stock SET units = 2 WHERE product_id = ?', [$productId]);
        }
    }

    public function testTheGuestWriterRefusesAUniquePieceRemovedFromStorageWhileItsMirrorStillHoldsIt(): void
    {
        $sessionId = $this->connect(self::CLICKER);
        $this->guest = new GuestCartsLibraryAgent();
        $this->underAgent($this->guest, fn () => $this->startAgent($this->guest));
        $this->subscribe(self::CLICKER, self::CATEGORY);
        $productId = self::productId(self::UNIQUE_SLUG);
        $stock = Database::sql('SELECT id, warehouse_id, units FROM product_warehouse_stock WHERE product_id = ?', [$productId])->firstRow();
        self::assertNotNull($stock);

        // Use a complete mirror so the stored deletion cannot reach it through a lazy column query.
        Hilos::$db->productWarehouseStocks->objectCollection->preloadAll();
        Database::sqlRun('DELETE FROM product_warehouse_stock WHERE id = ?', [$stock['id']]);
        try {
            self::assertSame(1, CartLineRules::unitsInStock($productId));
            $this->click($productId, 'add-unique-after-stock-removed');
            $states = $this->completeAdd($this->guest, $productId, self::UNIQUE_SLUG, $sessionId, null, 'add-unique-after-stock-removed');

            self::assertSame([], $states);
            $this->assertReply('add-unique-after-stock-removed', 'This piece is not in a warehouse yet');
            self::assertSame([], self::guestQuantities($sessionId));
        } finally {
            Database::sqlRun(
                'INSERT INTO product_warehouse_stock (id, product_id, warehouse_id, units) VALUES (?, ?, ?, ?)',
                [$stock['id'], $productId, $stock['warehouse_id'], $stock['units']],
            );
        }
    }

    private function connect(string $acceptKey, ?int $userId = null): int
    {
        $session = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint());
        $sessionId = $session->id ?? self::fail('Session fixture has no ID');
        $this->sessionIds[] = $sessionId;
        if ($userId !== null) {
            $session->actions->bindUser($userId);
        }
        ExecutionContext::run(
            new ExecutionFrame(agentId: self::CONNECTIONS_OWNER),
            static fn () => Hilos::$rt->connections->actions->register($acceptKey, $userId, $session->token, $sessionId),
        );

        return $sessionId;
    }

    private function person(): int
    {
        $person = Hilos::$db->users->actions->createWithName('Category cart visitor');
        $userId = $person->id ?? self::fail('Account fixture has no ID');
        $this->userIds[] = $userId;

        return $userId;
    }

    private function subscribe(string $acceptKey, string $slug): void
    {
        $this->drainSignals();
        $this->deliver($this->categories, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(CategoryPage::PAGE),
            new WebSocketPageSubscribeSignalDTO($acceptKey, CategoryPage::PAGE, [CategoryPageSubscribeParams::SLUG => $slug]),
        ));

        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->data instanceof PageResponseSignalData) {
                self::assertSame($acceptKey, $signal->data->targetAcceptKey);
                $responses[] = $signal->data->data;
            }
        }
        self::assertCount(1, $responses);
        self::assertSame($slug, Hilos::$sr->pageSubscription($acceptKey)?->stringParams()[CategoryPageSubscribeParams::SLUG]);
        self::assertNotNull(Hilos::$sr->pageResource($acceptKey));
    }

    private function click(int $productId, string $requestId): void
    {
        $action = EcommerceShopSignalConstants::CATEGORY_ADD_TO_CART;
        $frame = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName($action),
            new WebSocketActionSignalDTO(self::CLICKER, $action, [CartStateSignalData::PRODUCT_ID => $productId], $requestId),
        );
        self::assertEquals([new AgentDestination(AgentType::CATEGORIES_LIBRARY)], Hilos::$sr->getDestinations($frame));

        $this->deliver($this->categories, $frame);
    }

    /** @return list<array{?string, array<string, mixed>}> Cart snapshots as pairs of target group and body */
    private function completeAdd(
        AgentInterface $owner,
        int $productId,
        string $productSlug,
        int $sessionId,
        ?int $userId,
        string $requestId,
        ?Closure $beforeReply = null,
    ): array {
        $asks = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(WebSocketSignalData::class, $signal->data, 'The page defers its acknowledgement.');
            if ($signal->data instanceof AgentSignalData) {
                $asks[] = $signal;
            }
        }
        self::assertCount(1, $asks);
        [$ask] = $asks;
        self::assertNotNull($ask->data->receiptId);
        $payload = $ask->data->data;
        self::assertInstanceOf(CartAddProductSignalData::class, $payload);
        self::assertSame($productId, $payload->productId);
        self::assertSame($productSlug, $payload->productSlug);
        self::assertSame($sessionId, $payload->sessionId);
        self::assertSame($userId, $payload->userId);
        self::assertSame($userId === null ? Hilos::$db->sessions[$sessionId]->cartEpoch : null, $payload->cartEpoch);
        self::assertSame(self::CLICKER, $payload->acceptKey);
        self::assertSame($requestId, $payload->requestId);
        self::assertSame(EcommerceShopSignalConstants::CART_CATEGORY_ADD_DONE, $payload->replySignal);
        self::assertNull($payload->successMessage);
        self::assertEquals([new AgentDestination($owner->getType(), $owner->getIndex())], Hilos::$sr->getDestinations($ask));
        $this->deliver($owner, $ask);
        self::assertFalse(Database::hasOpenTransactions());

        $states = [];
        $answers = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData) {
                self::assertSame(EcommerceShopSignalConstants::CART_STATE, $signal->signalName->getName());
                self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
                self::assertNull($data->targetAcceptKey);
                self::assertInstanceOf(CartStateSignalData::class, $data->data);
                $states[] = [$data->targetGroup, $data->data->toArray()];
            } elseif ($data instanceof AgentSignalData) {
                self::assertSame(EcommerceShopSignalConstants::CART_CATEGORY_ADD_DONE, $signal->signalName->getName());
                self::assertInstanceOf(CartAddProductDoneSignalData::class, $data->data);
                self::assertSame($productSlug, $data->data->productSlug);
                self::assertNull($data->data->answer->successMessage);
                self::assertNull($data->data->answer->reply);
                $answers[] = $signal;
            }
        }
        self::assertCount(1, $answers);
        [$answer] = $answers;
        $beforeReply?->__invoke();
        self::assertEquals([new AgentDestination(AgentType::CATEGORIES_LIBRARY)], Hilos::$sr->getDestinations($answer));
        $this->deliver($this->categories, $answer);

        return $states;
    }

    private function deliver(AgentInterface $agent, SignalDTO $signal): void
    {
        $agents = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($this->worker);
        $id = $agent->getId();
        if (!$agents->hasAgent($id)) {
            $agents->addAgent($id, $agent);
        }

        $message = new DaemonAgentMessageDTO($id, $signal);
        ExecutionContext::run(
            new ExecutionFrame(),
            fn () => $this->worker->handleDaemonMessage(DaemonAgentMessageDTO::fromArray($message->toArray())),
        );
    }

    private function assertReply(string $requestId, ?string $reason = null): void
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(AgentSignalData::class, $signal->data, 'Unexpected handover.');
            if ($signal->data instanceof DbSyncSignalDataInterface) {
                self::assertSame(HilosDbContext::changeLogReceipts, $signal->data->collectionKey, 'Unexpected write.');
            }
            if ($signal->data instanceof WebSocketSignalData) {
                self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
                self::assertSame(self::CLICKER, $signal->data->targetAcceptKey);
                $reply = $signal->data->data;
                self::assertInstanceOf(WebSocketEnvelopeAware::class, $reply);
                self::assertSame($requestId, $reply->getEnvelopeRequestId());
                $replies[] = [$signal->signalName->getName(), $reply->getEnvelopeOutcome(), $reply->toArray()];
            }
        }

        $expected = $reason === null
            ? [SignalConstants::ACTION_SUCCESS, 'success', ['action' => 'category_add_to_cart', 'requestId' => $requestId]]
            : [SignalConstants::ACTION_ERROR, 'fail', ['action' => 'category_add_to_cart', 'reason' => $reason, 'requestId' => $requestId]];
        self::assertSame([$expected], $replies);
    }

    private static function productId(string $slug): int
    {
        $id = Database::sql('SELECT id FROM product WHERE slug = ?', [$slug])->field('id');
        self::assertNotNull($id);

        return (int) $id;
    }

    /** @return array{userId: ?int, lines: list<array{productId: int, quantity: int}>, voucherId: ?int} Expected cart snapshot */
    private static function cartBody(?int $userId, int $productId, int $quantity, ?int $voucherId): array
    {
        return [
            'userId' => $userId,
            'lines' => [['productId' => $productId, 'quantity' => $quantity]],
            'voucherId' => $voucherId,
        ];
    }

    /** @return list<int> Independently read quantities in one guest cart */
    private static function guestQuantities(int $sessionId): array
    {
        return array_map('intval', array_column(
            Database::sql('SELECT quantity FROM session_product_cart WHERE session_id = ?', [$sessionId])->rows(),
            'quantity',
        ));
    }

    /** @return list<int> Independently read quantities in one account cart */
    private static function accountQuantities(int $userId): array
    {
        return array_map('intval', array_column(
            Database::sql('SELECT quantity FROM user_product_cart WHERE user_id = ?', [$userId])->rows(),
            'quantity',
        ));
    }
}
