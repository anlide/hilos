<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\CLI\DTO\GuestCartSetQuantityCommandData;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Pages\DTO\CartRemoveLineActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\SqlRuntime\LockWaitTimeoutException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * What the owners of the carts tell the cart groups: the whole cart, after the commit.
 *
 * Real commits: an owner sends the cart once its transaction commits, and a rollback-scoped case
 * discards what waits for that commit, so the frames are read here and not beside the cart
 * actions. The guest carts library and the person's agent start the way their workers start
 * them. Lines written before the library starts enter its mirror, and a line written past it
 * after startup is visible only in storage. The person's agent reads its person's lines from
 * the database. Cases use products from the seeded catalog. Teardown removes the case's lines,
 * sessions, people, and vouchers, and forgets the memory that read them.
 */
final class CartStateTest extends IntegrationTestCase
{
    private const int SESSION = 101;
    private const int OTHER_SESSION = 102;
    private const string FIRST_TAB = 'cart-step-first';
    private const string SECOND_TAB = 'cart-voucher-second';
    private const string OTHER_TAB = 'cart-step-other';
    private const string CONNECTIONS_OWNER = 'cart-step-test';
    private const string STEP_ACTION = EcommerceShopSignalConstants::CART_STEP_QUANTITY;
    private const string SET_ACTION = EcommerceShopSignalConstants::CART_SET_QUANTITY;
    private const string VOUCHER_ACTION = EcommerceShopSignalConstants::CART_APPLY_VOUCHER;
    private const string LINE_UNAVAILABLE = 'This cart item is no longer available.';

    /** @var list<int> People the case created, removed after it */
    private array $userIds = [];

    /** @var list<int> Catalog vouchers the case created, removed after it */
    private array $voucherIds = [];

    /** @var list<int> Guest sessions the case created, removed after it */
    private array $guestSessionIds = [];

    private ?GuestCartsLibraryAgent $carts = null;
    private ?EcommerceShopWorkerManager $cartWorker = null;
    private ?BrowserContext $previousBrowser = null;

    /** @var array{id: int, units: int}|null Stock row changed by an account action case */
    private ?array $previousStock = null;

    /** Mounts the shop's router, so the frames the owners send have somewhere to queue. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new EcommerceShopSignalRouter());
    }

    /** Removes the case's lines and people, releases the guest carts library, and forgets what was read, even after a failed case. */
    protected function tearDown(): void
    {
        try {
            if ($this->previousStock !== null) {
                Database::sql('UPDATE product_warehouse_stock SET units = ? WHERE id = ?', [
                    $this->previousStock['units'],
                    $this->previousStock['id'],
                ]);
            }
            Database::sqlRun('DELETE FROM `session_product_cart` WHERE `session_id` IN (?, ?)', [self::SESSION, self::OTHER_SESSION]);
            foreach ($this->guestSessionIds as $sessionId) {
                Database::sqlRun('DELETE FROM `session_product_cart` WHERE `session_id` = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM `session_voucher_cart` WHERE `session_id` = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM `hilos_session` WHERE `id` = ?', [$sessionId]);
            }
            if ($this->userIds !== []) {
                $people = implode(',', $this->userIds);
                Database::sqlRun("DELETE FROM `user_product_cart` WHERE `user_id` IN ({$people})");
                Database::sqlRun("DELETE FROM `user_voucher_cart` WHERE `user_id` IN ({$people})");
                Database::sqlRun("DELETE FROM `hilos_user` WHERE `id` IN ({$people})");
            }
            if ($this->voucherIds !== []) {
                $vouchers = implode(',', $this->voucherIds);
                Database::sqlRun("DELETE FROM `voucher` WHERE `id` IN ({$vouchers})");
            }
            Hilos::$db->forgetLoadedDataForTest();
        } finally {
            if ($this->carts !== null) {
                TruthSourceRegistry::unregisterAgent($this->carts->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->carts->getId()));
            }
            $this->carts = null;
            $this->userIds = [];
            $this->voucherIds = [];
            $this->guestSessionIds = [];
            if ($this->cartWorker !== null) {
                $this->releaseCartWorker();
            }
            Hilos::$sr = null;
            parent::tearDown();
        }
    }

    public function testGuestStepsTheSavedLineAndPublishesOnlyToItsSession(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $sessionId = $this->storedGuestSession();
        $otherSessionId = $this->storedGuestSession();
        $lineId = self::guestLine($sessionId, $productId, 2);
        $otherLineId = self::guestLine($otherSessionId, $productId, 1);
        $voucher = $this->createVoucher('CARTSTEP10', 10);
        Database::sql('INSERT INTO session_voucher_cart (session_id, voucher_id) VALUES (?, ?)', [$sessionId, $voucher->id]);
        $this->startCartWorker();
        $guest = $this->guestCarts();
        $this->connectCartTab(self::FIRST_TAB, null, $sessionId);
        $this->connectCartTab(self::OTHER_TAB, null, $otherSessionId);
        $this->subscribeCart($guest, self::FIRST_TAB);
        $initial = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([$lineId], array_column($initial['lines'], 'lineId'));
        $this->subscribeCart($guest, self::OTHER_TAB);
        self::assertSame([$otherLineId], array_column($this->takeCartPage(self::OTHER_TAB)['lines'], 'lineId'));
        $group = CartGroup::forSession($sessionId);
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(self::FIRST_TAB, $group));
        $otherGroup = CartGroup::forSession($otherSessionId);
        Hilos::$sr->subscribeToGroup($otherGroup, new WebSocketGroupSubscribeSignalDTO(self::OTHER_TAB, $otherGroup));

        foreach ([CartLineRules::STEP_UP => 3, CartLineRules::STEP_DOWN => 2] as $step => $quantity) {
            $requestId = $step === CartLineRules::STEP_UP ? 'guest-plus' : 'guest-minus';
            $this->dispatchCartStep($guest, $requestId, $lineId, CartStepQuantityActionDTO::GUEST, $step);
            [$facts, $states, $reply] = $this->takeCartActionOutcome($requestId);
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
            self::assertNull($reply->message);
            self::assertNull($reply->reply);
            self::assertCount(1, $facts);
            self::assertSame(EcommerceShopDbContext::sessionProductCarts, $facts[0]->collectionKey);
            self::assertSame((string)$lineId, $facts[0]->idString);
            self::assertSame([SessionProductCart::quantity => $quantity], $facts[0]->row);
            self::assertSame($requestId, $facts[0]->originRequestId);
            self::assertSame($quantity, self::storedQuantity(SessionProductCart::_table, $lineId));
            self::assertSame(1, self::storedQuantity(SessionProductCart::_table, $otherLineId));
            self::assertSame([[$group, [
                'userId' => null,
                'lines' => [['productId' => $productId, 'quantity' => $quantity]],
                'voucherId' => $voucher->id,
            ], [self::FIRST_TAB]]], $states);

            $this->flushCartUpdates(...$facts);
            $cart = $this->takeCartPage(self::FIRST_TAB);
            self::assertSame([$lineId], array_column($cart['lines'], 'lineId'));
            self::assertSame([$quantity], array_column($cart['lines'], 'quantity'));
            self::assertSame((string)($initial['lines'][0]['price'] * $quantity), $cart['itemsTotal']);
        }
    }

    public function testAccountStepUpUsesFallenStockAndStepDownStillCommits(): void
    {
        $productId = self::seededProduct('hanging-planter-cotton-cord');
        $userId = $this->person('Cart step shopper');
        $sessionId = $this->storedGuestSession();
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $lineId = self::accountLine($userId, $productId, 5);
        $this->startCartWorker();
        $owner = $this->personAgent($userId);
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        $initial = $this->takeCartPage(self::FIRST_TAB);
        self::assertGreaterThan(5, $initial['lines'][0]['availableQuantity']);
        $group = CartGroup::forUser($userId);
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(self::FIRST_TAB, $group));

        $stockFact = $this->lowerStock($productId, 2);
        self::assertSame($initial['lines'][0]['availableQuantity'], Hilos::$db->productWarehouseStocks[$stockFact->idString]->units);
        $this->dispatchCartStep($owner, 'account-plus', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_UP);
        $this->assertCartActionRefused('account-plus', 'Only 2 in stock');
        self::assertSame(5, self::storedQuantity(UserProductCart::_table, $lineId));

        $this->flushCartUpdates($stockFact);
        self::assertSame(2, $this->takeCartPage(self::FIRST_TAB)['lines'][0]['availableQuantity']);

        $this->dispatchCartStep($owner, 'account-minus', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_DOWN);
        [$facts, $states, $reply] = $this->takeCartActionOutcome('account-minus');
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertCount(1, $facts);
        self::assertSame(EcommerceShopDbContext::userProductCarts, $facts[0]->collectionKey);
        self::assertSame((string)$lineId, $facts[0]->idString);
        self::assertSame(4, self::storedQuantity(UserProductCart::_table, $lineId));
        self::assertSame([[$group, [
            'userId' => $userId,
            'lines' => [['productId' => $productId, 'quantity' => 4]],
            'voucherId' => null,
        ], [self::FIRST_TAB]]], $states);
    }

    public function testAccountStepRejectsAReboundSessionBeforeTheMirrorsCatchUp(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $userId = $this->person('Cart step shopper');
        $otherUserId = $this->person('New session owner');
        $sessionId = $this->storedGuestSession();
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $lineId = self::accountLine($userId, $productId, 2);
        $this->startCartWorker();
        $owner = $this->personAgent($userId);
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        self::assertSame([$lineId], array_column($this->takeCartPage(self::FIRST_TAB)['lines'], 'lineId'));
        $group = CartGroup::forUser($userId);
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(self::FIRST_TAB, $group));

        $this->rebindStoredSession($sessionId, $otherUserId);
        self::assertSame($userId, Hilos::$db->sessions[$sessionId]->userId);

        $this->dispatchCartStep($owner, 'account-rebound', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_DOWN);
        $this->assertCartActionRefused('account-rebound', self::LINE_UNAVAILABLE);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $lineId));
    }

    public function testAccountStepRejectsAConnectionWithoutASessionId(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $userId = $this->person('Cart step shopper');
        $sessionId = $this->storedGuestSession();
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $lineId = self::accountLine($userId, $productId, 2);
        $this->startCartWorker();
        $owner = $this->personAgent($userId);
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        self::assertSame([$lineId], array_column($this->takeCartPage(self::FIRST_TAB)['lines'], 'lineId'));

        $addressed = self::cartStepSignal('account-no-session', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_DOWN);
        self::assertEquals([new AgentDestination($owner->getType(), $owner->getIndex())], Hilos::$sr->getDestinations($addressed));
        ExecutionContext::run(new ExecutionFrame(), static function () use ($sessionId): void {
            Hilos::$rt->connections->actions->repointSessionToken(self::FIRST_TAB, Hilos::$db->sessions[$sessionId]->token, null);
        });
        self::assertSame($userId, Hilos::$rt->connections[self::FIRST_TAB]?->userId);
        self::assertNull(Hilos::$rt->connections[self::FIRST_TAB]?->sessionId);
        $this->deliverCartFrame($owner, $addressed);
        $this->assertCartActionRefused('account-no-session', self::LINE_UNAVAILABLE);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $lineId));
    }

    public function testCartStepRejectsForeignRecreatedAndPreviousOwnerLines(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $sessionId = $this->storedGuestSession();
        $userId = $this->person('Cart handoff shopper');
        $lineId = self::guestLine($sessionId, $productId, 2);
        $foreignLineId = self::guestLine(self::OTHER_SESSION, $productId, 2);
        $accountLineId = self::accountLine($userId, $productId, 2);
        $this->startCartWorker();
        $guest = $this->guestCarts();
        $this->connectCartTab(self::FIRST_TAB, null, $sessionId);
        self::assertSame([], Hilos::$sr->getDestinations(self::cartStepSignal(
            'unsubscribed', $lineId, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP,
        )));
        $this->subscribeCart($guest, self::FIRST_TAB);
        $this->takeCartPage(self::FIRST_TAB);

        $this->dispatchCartStep($guest, 'foreign', $foreignLineId, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        $this->assertCartActionRefused('foreign', self::LINE_UNAVAILABLE);
        self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $foreignLineId));

        $this->underAgent($guest, static function () use ($sessionId, $productId): void {
            Hilos::$db->sessionProductCarts->actions->remove($sessionId, $productId);
            Hilos::$db->sessionProductCarts->actions->add($sessionId, $productId);
        });
        $this->drainSignals();
        $replacement = (int)Database::sql(
            'SELECT id FROM session_product_cart WHERE session_id = ? AND product_id = ?', [$sessionId, $productId],
        )->field('id');
        self::assertNotSame($lineId, $replacement);

        $this->dispatchCartStep($guest, 'deleted', $lineId, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        $this->assertCartActionRefused('deleted', self::LINE_UNAVAILABLE);
        self::assertSame(1, self::storedQuantity(SessionProductCart::_table, $replacement));
        $this->dispatchCartStep($guest, 'fresh', $replacement, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        [, , $reply] = $this->takeCartActionOutcome('fresh');
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $replacement));

        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $this->drainSignals();
        $this->dispatchCartStep($guest, 'stored-owner-changed', $replacement, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        $this->assertCartActionRefused('stored-owner-changed', self::LINE_UNAVAILABLE);

        $oldAction = self::cartStepSignal('before-handoff', $replacement, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        ExecutionContext::run(new ExecutionFrame(), static function () use ($userId): void {
            Hilos::$rt->connections[self::FIRST_TAB]->actions->bindUser($userId);
        });
        $this->deliverCartFrame($guest, $oldAction);
        $this->assertCartActionRefused('before-handoff', self::LINE_UNAVAILABLE);

        $owner = $this->personAgent($userId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        $cart = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([$accountLineId], array_column($cart['lines'], 'lineId'));
        $this->dispatchCartStep($owner, 'after-handoff', $accountLineId, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        $this->assertCartActionRefused('after-handoff', self::LINE_UNAVAILABLE);

        $afterDeparture = self::cartStepSignal('after-departure', $accountLineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_UP);
        $this->deliverCartFrame($owner, self::webSocketFrame(
            SignalTypeConstants::PAGE_UNSUBSCRIBE, CartPage::PAGE, new WebSocketPageUnsubscribeSignalDTO(self::FIRST_TAB),
        ));
        $this->deliverCartFrame($owner, $afterDeparture);
        $this->assertCartActionRefused('after-departure', self::LINE_UNAVAILABLE);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $accountLineId));
        self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $replacement));
    }

    public function testGuestSetsExactQuantityAndPublishesOnlyTheFirstChangeToItsSession(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $sessionId = $this->storedGuestSession();
        $otherSessionId = $this->storedGuestSession();
        $lineId = self::guestLine($sessionId, $productId, 2);
        $otherLineId = self::guestLine($otherSessionId, $productId, 1);
        $voucher = $this->createVoucher('CARTEXACT10', 10);
        Database::sql('INSERT INTO session_voucher_cart (session_id, voucher_id) VALUES (?, ?)', [$sessionId, $voucher->id]);
        $this->startCartWorker();
        $guest = $this->guestCarts();
        $this->connectCartTab(self::FIRST_TAB, null, $sessionId);
        $this->connectCartTab(self::OTHER_TAB, null, $otherSessionId);
        $this->subscribeCart($guest, self::FIRST_TAB);
        $initial = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([$lineId], array_column($initial['lines'], 'lineId'));
        self::assertSame([2], array_column($initial['lines'], 'quantity'));
        $this->subscribeCart($guest, self::OTHER_TAB);
        self::assertSame([$otherLineId], array_column($this->takeCartPage(self::OTHER_TAB)['lines'], 'lineId'));
        $group = CartGroup::forSession($sessionId);
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(self::FIRST_TAB, $group));
        $otherGroup = CartGroup::forSession($otherSessionId);
        Hilos::$sr->subscribeToGroup($otherGroup, new WebSocketGroupSubscribeSignalDTO(self::OTHER_TAB, $otherGroup));

        $addressed = self::cartSetQuantitySignal('set-before-pause', $lineId, CartStepQuantityActionDTO::GUEST, '5', 2);
        self::assertEquals([new AgentDestination($guest->getType(), $guest->getIndex())], Hilos::$sr->getDestinations($addressed));
        $subscription = Hilos::$sr->pageSubscription(self::FIRST_TAB);
        self::assertNotNull($subscription);
        $subscription->retainedForRestart = true;
        try {
            self::assertFalse($subscription->admitsPageAnswer(self::FIRST_TAB));
            $this->deliverCartFrame($guest, $addressed);
            $this->assertCartActionRefused('set-before-pause', self::LINE_UNAVAILABLE, self::SET_ACTION);
            self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $lineId));
        } finally {
            $subscription->retainedForRestart = false;
        }
        self::assertTrue($subscription->admitsPageAnswer(self::FIRST_TAB));

        $this->dispatchCartSetQuantity($guest, 'guest-set', $lineId, CartStepQuantityActionDTO::GUEST, '5', 2);
        [$facts, $states, $reply] = $this->takeCartActionOutcome('guest-set', self::SET_ACTION);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertNull($reply->message);
        self::assertNull($reply->reply);
        self::assertCount(1, $facts);
        self::assertSame(EcommerceShopDbContext::sessionProductCarts, $facts[0]->collectionKey);
        self::assertSame((string)$lineId, $facts[0]->idString);
        self::assertSame([SessionProductCart::quantity => 5], $facts[0]->row);
        self::assertSame('guest-set', $facts[0]->originRequestId);
        self::assertSame(5, self::storedQuantity(SessionProductCart::_table, $lineId));
        self::assertSame(1, self::storedQuantity(SessionProductCart::_table, $otherLineId));
        self::assertSame([[$group, [
            'userId' => null,
            'lines' => [['productId' => $productId, 'quantity' => 5]],
            'voucherId' => $voucher->id,
        ], [self::FIRST_TAB]]], $states);

        $this->flushCartUpdates(...$facts);
        $cart = $this->takeCartPage(self::FIRST_TAB);
        self::assertNull($cart['userId']);
        self::assertNotEmpty($initial['guestCartKey']);
        self::assertSame($initial['guestCartKey'], $cart['guestCartKey']);
        self::assertSame([[...$initial['lines'][0], 'quantity' => 5, 'lineTotal' => '160000']], $cart['lines']);
        self::assertSame('160000', $cart['itemsTotal']);
        self::assertSame('144000', $cart['total']);

        $this->dispatchCartSetQuantity($guest, 'guest-set-again', $lineId, CartStepQuantityActionDTO::GUEST, '5', $cart['lines'][0]['quantity']);
        $this->flushCartUpdates();
        [$facts, $states, $reply] = $this->takeCartActionOutcome('guest-set-again', self::SET_ACTION);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertNull($reply->message);
        self::assertNull($reply->reply);
        self::assertSame([], $facts);
        self::assertSame([], $states);
        self::assertSame(5, self::storedQuantity(SessionProductCart::_table, $lineId));
    }

    public function testAccountSetsExactQuantityAgainstStoredStockAndKeepsTheLineOnRefusal(): void
    {
        $productId = self::seededProduct('hanging-planter-cotton-cord');
        $soldOutProductId = self::seededProduct('orchid-fertilizer-250-ml');
        $userId = $this->person('Cart quantity shopper');
        $sessionId = $this->storedGuestSession();
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $lineId = self::accountLine($userId, $productId, 5);
        $soldOutLineId = self::accountLine($userId, $soldOutProductId, 2);
        $this->startCartWorker();
        $owner = $this->personAgent($userId);
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        $initial = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([$lineId, $soldOutLineId], array_column($initial['lines'], 'lineId'));
        self::assertSame([5, 2], array_column($initial['lines'], 'quantity'));
        self::assertGreaterThanOrEqual(6, $initial['lines'][0]['availableQuantity']);
        self::assertSame(0, $initial['lines'][1]['availableQuantity']);
        $group = CartGroup::forUser($userId);
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(self::FIRST_TAB, $group));

        $stockFact = $this->lowerStock($productId, 2);
        self::assertSame($initial['lines'][0]['availableQuantity'], Hilos::$db->productWarehouseStocks[$stockFact->idString]->units);
        $this->dispatchCartSetQuantity($owner, 'fallen-stock', $lineId, CartStepQuantityActionDTO::ACCOUNT, '6', 5);
        $this->assertCartActionRefused('fallen-stock', 'Only 2 in stock', self::SET_ACTION);
        self::assertSame(5, self::storedQuantity(UserProductCart::_table, $lineId));

        $this->flushCartUpdates($stockFact);
        $cartAfterStock = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([
            [...$initial['lines'][0], 'availableQuantity' => 2, 'lineTotal' => '52000'],
            $initial['lines'][1],
        ], $cartAfterStock['lines']);
        self::assertSame('52000', $cartAfterStock['itemsTotal']);

        $this->dispatchCartSetQuantity($owner, 'zero-stock', $soldOutLineId, CartStepQuantityActionDTO::ACCOUNT, '1', 2);
        $this->assertCartActionRefused('zero-stock', 'This product is out of stock', self::SET_ACTION);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $soldOutLineId));

        foreach ([
            ['fractional-quantity', '1.5', 'Enter a whole number of units.'],
            ['zero-quantity', '0', 'A cart line keeps one unit at least: Remove takes it out'],
        ] as [$requestId, $quantity, $reason]) {
            $this->dispatchCartSetQuantity($owner, $requestId, $lineId, CartStepQuantityActionDTO::ACCOUNT, $quantity, 5);
            $this->assertCartActionRefused($requestId, $reason, self::SET_ACTION);
            self::assertSame(5, self::storedQuantity(UserProductCart::_table, $lineId));
        }

        $this->dispatchCartSetQuantity($owner, 'account-set', $lineId, CartStepQuantityActionDTO::ACCOUNT, '2', 5);
        [$facts, $states, $reply] = $this->takeCartActionOutcome('account-set', self::SET_ACTION);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertCount(1, $facts);
        self::assertSame(EcommerceShopDbContext::userProductCarts, $facts[0]->collectionKey);
        self::assertSame((string)$lineId, $facts[0]->idString);
        self::assertSame([UserProductCart::quantity => 2], $facts[0]->row);
        self::assertSame('account-set', $facts[0]->originRequestId);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $lineId));
        self::assertSame([[$group, [
            'userId' => $userId,
            'lines' => [
                ['productId' => $productId, 'quantity' => 2],
                ['productId' => $soldOutProductId, 'quantity' => 2],
            ],
            'voucherId' => null,
        ], [self::FIRST_TAB]]], $states);
        $this->flushCartUpdates(...$facts);
        $cart = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame($userId, $cart['userId']);
        self::assertNull($cart['guestCartKey']);
        self::assertSame([[...$cartAfterStock['lines'][0], 'quantity' => 2], $cartAfterStock['lines'][1]], $cart['lines']);
    }

    public function testExactQuantityRefusesAStaleEditForeignOrRecreatedLineAndReboundSession(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $sessionId = $this->storedGuestSession();
        $userId = $this->person('Cart quantity editor');
        $otherUserId = $this->person('Other cart owner');
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $lineId = self::accountLine($userId, $productId, 2);
        $foreignLineId = self::accountLine($otherUserId, $productId, 2);
        $this->startCartWorker();
        $owner = $this->personAgent($userId);
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        self::assertSame([], Hilos::$sr->getDestinations(self::cartSetQuantitySignal(
            'set-unsubscribed', $lineId, CartStepQuantityActionDTO::ACCOUNT, '4', 2,
        )));
        $this->subscribeCart($owner, self::FIRST_TAB);
        $initial = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([$lineId], array_column($initial['lines'], 'lineId'));
        self::assertSame([2], array_column($initial['lines'], 'quantity'));

        $this->dispatchCartStep($owner, 'intervening-minus', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_DOWN);
        [$facts, , $reply] = $this->takeCartActionOutcome('intervening-minus');
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        $this->flushCartUpdates(...$facts);
        self::assertSame([1], array_column($this->takeCartPage(self::FIRST_TAB)['lines'], 'quantity'));

        $this->dispatchCartSetQuantity($owner, 'stale-quantity', $lineId, CartStepQuantityActionDTO::ACCOUNT, '4', $initial['lines'][0]['quantity']);
        $this->assertCartActionRefused('stale-quantity', 'The cart quantity changed. Review it and try again.', self::SET_ACTION);
        self::assertSame(1, self::storedQuantity(UserProductCart::_table, $lineId));

        $this->dispatchCartSetQuantity($owner, 'foreign-line', $foreignLineId, CartStepQuantityActionDTO::ACCOUNT, '4', 2);
        $this->assertCartActionRefused('foreign-line', self::LINE_UNAVAILABLE, self::SET_ACTION);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $foreignLineId));
        self::assertSame(1, self::storedQuantity(UserProductCart::_table, $lineId));

        $this->underAgent($owner, static function () use ($userId, $productId): void {
            Hilos::$db->userProductCarts->actions->remove($userId, $productId);
            Hilos::$db->userProductCarts->actions->add($userId, $productId);
        });
        $this->drainSignals();
        $this->connectCartTab(self::OTHER_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::OTHER_TAB);
        $replacement = $this->takeCartPage(self::OTHER_TAB)['lines'][0]['lineId'];
        self::assertNotSame($lineId, $replacement);
        $this->dispatchCartSetQuantity($owner, 'deleted-line', $lineId, CartStepQuantityActionDTO::ACCOUNT, '4', 1);
        $this->assertCartActionRefused('deleted-line', self::LINE_UNAVAILABLE, self::SET_ACTION);
        self::assertSame(1, self::storedQuantity(UserProductCart::_table, $replacement));

        $this->rebindStoredSession($sessionId, $otherUserId);
        self::assertSame($userId, Hilos::$db->sessions[$sessionId]->userId);
        $this->dispatchCartSetQuantity($owner, 'rebound-session', $replacement, CartStepQuantityActionDTO::ACCOUNT, '4', 1);
        $this->assertCartActionRefused('rebound-session', self::LINE_UNAVAILABLE, self::SET_ACTION);
        self::assertSame(1, self::storedQuantity(UserProductCart::_table, $replacement));
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $foreignLineId));
    }

    /** A guest with a still-active connection mirror cannot edit after persisted expiry or rebinding. */
    public function testGuestExactQuantityRefusesAnExpiredOrReboundStoredSessionBeforeSweep(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $sessionId = $this->storedGuestSession();
        $session = Hilos::$db->sessions[$sessionId];
        $lineId = self::guestLine($sessionId, $productId, 2);
        $this->startCartWorker();
        $owner = $this->guestCarts();
        $this->connectCartTab(self::FIRST_TAB, null, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        self::assertSame([$lineId], array_column($this->takeCartPage(self::FIRST_TAB)['lines'], 'lineId'));

        $expiresAt = TimeHelper::nowMs();
        Database::sqlRun('UPDATE hilos_session SET expires_at = ? WHERE id = ?', [$expiresAt, $sessionId]);
        self::assertTrue($session->expiresAt === null || $session->expiresAt > $expiresAt);
        $this->dispatchCartSetQuantity($owner, 'expired-guest-set', $lineId, CartStepQuantityActionDTO::GUEST, '4', 2);
        $this->assertCartActionRefused('expired-guest-set', self::LINE_UNAVAILABLE, self::SET_ACTION);
        self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $lineId));

        Database::sqlRun('UPDATE hilos_session SET expires_at = ? WHERE id = ?', [$session->expiresAt, $sessionId]);
        $this->rebindStoredSession($sessionId, $this->person('Rebound guest'));
        self::assertNull($session->userId);
        $this->drainSignals();
        $this->dispatchCartSetQuantity($owner, 'rebound-guest-set', $lineId, CartStepQuantityActionDTO::GUEST, '4', 2);
        $this->assertCartActionRefused('rebound-guest-set', self::LINE_UNAVAILABLE, self::SET_ACTION);
        self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $lineId));
    }

    /** @param bool $registered Whether the action serves an account or a guest cart */
    #[DataProvider('exactQuantityOwners')]
    public function testExactQuantityHoldsTheSessionThroughTheWrite(bool $registered): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $sessionId = $this->storedGuestSession();
        $this->startCartWorker();
        if ($registered) {
            $userId = $this->person('Session lock holder');
            Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
            $lineId = self::accountLine($userId, $productId, 2);
            $owner = $this->personAgent($userId);
            $ownerKind = CartStepQuantityActionDTO::ACCOUNT;
            $table = UserProductCart::_table;
            $collection = EcommerceShopDbContext::userProductCarts;
        } else {
            $userId = null;
            $lineId = self::guestLine($sessionId, $productId, 2);
            $owner = $this->guestCarts();
            $ownerKind = CartStepQuantityActionDTO::GUEST;
            $table = SessionProductCart::_table;
            $collection = EcommerceShopDbContext::sessionProductCarts;
        }
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        self::assertSame([$lineId], array_column($this->takeCartPage(self::FIRST_TAB)['lines'], 'lineId'));

        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $peer = new mysqli($primary->host, $primary->user, $primary->password, $primary->database, $primary->port, $primary->socket);
        $mirror = new class ($collection, (string)$lineId) implements SourceMirrorSubscriberInterface {
            public ?Closure $probe = null;

            public function __construct(private readonly string $collection, private readonly string $lineId)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === $this->collection && $change->sourceId === $this->lineId
                    && $change->mutationType === TableMutationType::Update
                ) {
                    ($this->probe)?->__invoke();
                }
            }
        };
        $lockSession = static fn () => $peer->execute_query('SELECT id FROM hilos_session WHERE id = ? FOR UPDATE NOWAIT', [$sessionId]);
        $probeErrors = [];
        $mirror->probe = static function () use ($peer, $lockSession, &$probeErrors): void {
            $peer->begin_transaction();
            try {
                $lockSession()->free();
                $probeErrors[] = null;
            } catch (mysqli_sql_exception $failure) {
                $probeErrors[] = $failure->getCode();
            } finally {
                $peer->rollback();
            }
        };
        SourceChangeBus::subscribe($mirror);
        try {
            $this->dispatchCartSetQuantity($owner, 'set-with-session-lock', $lineId, $ownerKind, '4', 2);
            [$facts, , $reply] = $this->takeCartActionOutcome('set-with-session-lock', self::SET_ACTION);
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
            self::assertCount(1, $facts);
            self::assertSame(4, self::storedQuantity($table, $lineId));
            self::assertSame([LockWaitTimeoutException::MYSQL_ERROR_CODE], $probeErrors);
            $mirror->probe = null;

            $peer->begin_transaction();
            try {
                $result = $lockSession();
                self::assertSame($sessionId, (int)$result->fetch_column());
                $result->free();
                $this->dispatchCartSetQuantity($owner, 'set-with-busy-session', $lineId, $ownerKind, '5', 4);
                $this->assertCartActionRefused('set-with-busy-session', self::LINE_UNAVAILABLE, self::SET_ACTION);
                self::assertSame(4, self::storedQuantity($table, $lineId));
            } finally {
                $peer->rollback();
            }
        } finally {
            $mirror->probe = null;
            $peer->close();
        }
    }

    /** @return iterable<string, array{bool}> Both transactional cart writers */
    public static function exactQuantityOwners(): iterable
    {
        yield 'guest' => [false];
        yield 'account' => [true];
    }

    /** Asked for a session's cart, the library tells the session's group every line, in the order put in, and no line of another session. */
    public function testTheGuestCartsLibraryTellsTheSessionsGroupItsWholeCart(): void
    {
        [$roses, $tulips] = self::seededProducts();
        self::guestLine(self::SESSION, $tulips, 2);
        self::guestLine(self::SESSION, $roses, 1);
        self::guestLine(self::OTHER_SESSION, $roses, 3);
        $this->drainSignals();

        $this->askGuestCart(self::SESSION);

        self::assertSame(
            [[CartGroup::forSession(self::SESSION), [
                'userId' => null,
                'lines' => [['productId' => $tulips, 'quantity' => 2], ['productId' => $roses, 'quantity' => 1]],
                'voucherId' => null,
            ]]],
            $this->cartStates(),
        );
    }

    /** An empty cart is told too, as no lines: a tab still showing lines it no longer has learns that. */
    public function testAnEmptyGuestCartIsToldAsNoLines(): void
    {
        $this->drainSignals();

        $this->askGuestCart(self::SESSION);

        self::assertSame(
            [[CartGroup::forSession(self::SESSION), ['userId' => null, 'lines' => [], 'voucherId' => null]]],
            $this->cartStates(),
        );
    }

    /** The sweep tells no cart: no browser presents a swept session again. */
    public function testTheSweepTellsNoCart(): void
    {
        [$roses] = self::seededProducts();
        self::guestLine(self::SESSION, $roses, 1);
        $carts = $this->guestCarts();
        $this->drainSignals();

        $this->underAgent($carts, static fn () => $carts->onSignalAgent(
            new AgentSignalData(new SessionsSweptSignalData(['swept-' . self::SESSION], [self::SESSION])),
            '',
            HilosSignalConstants::HILOS_SESSIONS_SWEPT,
        ));

        self::assertSame([], $this->cartStates());
    }

    /** Asked for the person's cart, the person's agent tells the person's group every line, in the order they were put in. */
    public function testThePersonsAgentTellsThePersonsGroupTheWholeCart(): void
    {
        [$roses, $tulips] = self::seededProducts();
        $personId = $this->person('Cart told');
        $otherId = $this->person('Cart untold');
        self::accountLine($personId, $tulips, 2);
        self::accountLine($personId, $roses, 1);
        self::accountLine($otherId, $roses, 3);
        $this->drainSignals();

        $this->askAccountCart($personId);

        self::assertSame(
            [[CartGroup::forUser($personId), [
                'userId' => $personId,
                'lines' => [['productId' => $tulips, 'quantity' => 2], ['productId' => $roses, 'quantity' => 1]],
                'voucherId' => null,
            ]]],
            $this->cartStates(),
        );
    }

    /** An ask about another person reaching the person's agent is refused, and no cart is told. */
    public function testAnAskAboutAnotherPersonIsRefusedAndTellsNothing(): void
    {
        [$roses] = self::seededProducts();
        $personId = $this->person('Cart addressee');
        $otherId = $this->person('Cart stranger');
        self::accountLine($otherId, $roses, 1);
        $agent = $this->personAgent($personId);
        $this->drainSignals();

        try {
            $this->underAgent($agent, static fn () => $agent->onSignalAgent(
                new AgentSignalData(new AccountCartSendSignalData($otherId)),
                '',
                EcommerceShopSignalConstants::ACCOUNT_CART_SEND,
            ));
            self::fail('The ask names another person than the agent\'s');
        } catch (AgentException) {
            self::assertSame([], $this->cartStates());
        }
    }

    /** The fixture stores exact quantities, announces committed changes, and leaves repeated requests unchanged. */
    public function testGuestCartFixtureSetsAndRemovesExactQuantities(): void
    {
        $token = SessionToken::mint();
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $sessionId = $session->id ?? self::fail('The guest session was not persisted');
        $this->guestSessionIds[] = $sessionId;
        $otherSessionId = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint())->id
            ?? self::fail('The control session was not persisted');
        $this->guestSessionIds[] = $otherSessionId;
        $productId = self::seededProduct('pine-bark-5-l');
        self::guestLine($otherSessionId, $productId, 1);

        foreach ([[3, true], [3, false], [2, true], [0, true], [0, false]] as [$quantity, $changed]) {
            [$reply, $frames] = $this->guestFixture($token, $productId, $quantity);
            self::assertTrue($reply->isOk());
            self::assertSame(['quantity' => $quantity], $reply->payload);
            self::assertSame($quantity === 0 ? null : $quantity, self::storedGuestQuantity($sessionId, $productId));
            self::assertSame(1, self::storedGuestQuantity($otherSessionId, $productId));
            $lines = $quantity === 0 ? [] : [['productId' => $productId, 'quantity' => $quantity]];
            $cart = ['userId' => null, 'lines' => $lines, 'voucherId' => null];
            self::assertSame($changed ? [[CartGroup::forSession($sessionId), $cart]] : [], $frames);
            $this->askGuestCart($sessionId);
            self::assertSame([[CartGroup::forSession($sessionId), $cart]], $this->cartStates());
        }
    }

    /** A fixture removal cannot succeed while storage still holds a line missing from the owner's mirror. */
    public function testGuestCartFixtureRefusesRemovalWhenTheMirrorMissesTheStoredLine(): void
    {
        $token = SessionToken::mint();
        $sessionId = Hilos::$db->sessions->actions->createAnonymous($token)->id ?? self::fail('The guest session was not persisted');
        $this->guestSessionIds[] = $sessionId;
        $otherSessionId = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint())->id
            ?? self::fail('The control session was not persisted');
        $this->guestSessionIds[] = $otherSessionId;
        $productId = self::seededProduct('morning-meadow');

        $this->guestCarts();
        self::guestLine($sessionId, $productId, 1);
        self::guestLine($otherSessionId, $productId, 1);
        self::assertSame(1, self::storedGuestQuantity($sessionId, $productId));
        self::assertSame(1, self::storedGuestQuantity($otherSessionId, $productId));
        $this->askGuestCart($sessionId);
        self::assertSame(
            [[CartGroup::forSession($sessionId), ['userId' => null, 'lines' => [], 'voucherId' => null]]],
            $this->cartStates(),
        );

        [$reply, $frames] = $this->guestFixture($token, $productId, 0);

        self::assertSame(1, self::storedGuestQuantity($sessionId, $productId));
        self::assertSame(1, self::storedGuestQuantity($otherSessionId, $productId));
        self::assertSame([], $frames);
        self::assertStringNotContainsString($token, $reply->toJson());
        self::assertSame(CommandConstants::STATUS_ERROR, $reply->status);
        self::assertSame([CommandConstants::FIELD_MESSAGE => 'The guest cart fixture removal could not be confirmed'], $reply->payload);
    }

    /** Refused exact quantities leave no partial line, and invalid sessions cannot change the cart. */
    public function testGuestCartFixtureRefusesUnavailableSessionsAndInvalidQuantities(): void
    {
        $token = SessionToken::mint();
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $sessionId = $session->id ?? self::fail('The guest session was not persisted');
        $this->guestSessionIds[] = $sessionId;
        $stock = self::seededProduct('pine-bark-5-l');
        $unique = self::seededProduct('morning-meadow');

        $this->assertFixtureRefused($token, $stock, 999_999, 'Only 120 in stock');
        $this->assertFixtureRefused($token, $unique, 2, 'A unique piece has no quantity: it is one of a kind');
        $this->assertFixtureRefused($token, PHP_INT_MAX, 1, 'This product does not exist');
        $this->askGuestCart($sessionId);
        self::assertSame(
            [[CartGroup::forSession($sessionId), ['userId' => null, 'lines' => [], 'voucherId' => null]]],
            $this->cartStates(),
        );
        self::assertSame(0, (int)Database::sql(
            'SELECT COUNT(*) AS total FROM session_product_cart WHERE session_id = ?',
            [$sessionId],
        )->field('total'));

        $unavailable = 'This guest session is no longer available';
        $this->assertFixtureRefused(SessionToken::mint(), $stock, 1, $unavailable);
        $session->actions->bindUser($this->person('Fixture account'));
        $this->assertFixtureRefused($token, $stock, 1, $unavailable);
        $session->actions->unbindUser();
        $session->actions->expire();
        $this->assertFixtureRefused($token, $stock, 1, $unavailable);

        $env = Hilos::$env;
        Hilos::$env = null;
        try {
            $this->assertFixtureRefused($token, $stock, 1, 'Setting a guest cart fixture is test-only');
        } finally {
            Hilos::$env = $env;
        }
    }

    /** Expiry committed before the worker hears the change still closes the fixture command. */
    public function testGuestCartFixtureRefusesAnExpiredStoredSessionWithAnActiveMirror(): void
    {
        $token = SessionToken::mint();
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $sessionId = $session->id ?? self::fail('The guest session was not persisted');
        $this->guestSessionIds[] = $sessionId;
        $productId = self::seededProduct('pine-bark-5-l');
        [$reply] = $this->guestFixture($token, $productId, 2);
        self::assertTrue($reply->isOk());

        // Update storage as another worker would, with its DB_SYNC still in flight.
        $expiresAt = TimeHelper::nowMs();
        Database::sqlRun('UPDATE `hilos_session` SET `expires_at` = ? WHERE `id` = ?', [$expiresAt, $sessionId]);
        self::assertTrue($session->expiresAt === null || $session->expiresAt > $expiresAt);

        $this->assertFixtureRefused($token, $productId, 1, 'This guest session is no longer available');
        self::assertSame(2, self::storedGuestQuantity($sessionId, $productId));
    }

    /** A guest's voucher-only cart is told on commit, on change and on request. */
    public function testGuestVoucherChangesAreToldToTheSessionGroup(): void
    {
        $sessionId = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint())->id
            ?? self::fail('The guest session was not persisted');
        $this->guestSessionIds[] = $sessionId;
        $group = CartGroup::forSession($sessionId);
        $first = $this->createVoucher('CART10', 10);
        $second = $this->createVoucher('CART20', 20);
        $carts = $this->guestCarts();
        $this->drainSignals();

        $this->underAgent($carts, fn () => Database::transaction(DatabaseNames::PRIMARY, function () use ($carts, $sessionId): void {
            $carts->applyVoucher($sessionId, 'CART10');
            self::assertSame([], $this->cartStates());
        }));
        self::assertSame([[$group, ['userId' => null, 'lines' => [], 'voucherId' => $first->id]]], $this->cartStates());

        $this->underAgent($carts, fn () => $this->assertRolledBackWithoutCart(
            static fn () => $carts->applyVoucher($sessionId, 'CART20'),
        ));
        $this->askGuestCart($sessionId);
        self::assertSame([[$group, ['userId' => null, 'lines' => [], 'voucherId' => $first->id]]], $this->cartStates());

        $this->underAgent($carts, static fn () => $carts->applyVoucher($sessionId, 'CART20'));
        self::assertSame([[$group, ['userId' => null, 'lines' => [], 'voucherId' => $second->id]]], $this->cartStates());
        $this->underAgent($carts, static fn () => $carts->applyVoucher($sessionId, 'cart20'));
        self::assertSame([], $this->cartStates());

        $this->underVoucherLibrary(static fn () => $second->actions->setActive(false));
        $this->assertRefusedWithoutCart(fn () => $this->underAgent($carts, static fn () => $carts->applyVoucher($sessionId, 'CART20')));
        $this->askGuestCart($sessionId);
        self::assertSame([[$group, ['userId' => null, 'lines' => [], 'voucherId' => $second->id]]], $this->cartStates());

        $this->underAgent($carts, static fn () => $carts->removeVoucher($sessionId));
        self::assertSame([[$group, ['userId' => null, 'lines' => [], 'voucherId' => null]]], $this->cartStates());
        $this->underAgent($carts, static fn () => $carts->removeVoucher($sessionId));
        self::assertSame([], $this->cartStates());
    }

    /** A person's voucher changes and initial asks tell the whole cart to that person's group. */
    public function testAccountVoucherChangesAreToldToThePersonGroup(): void
    {
        [$roses] = self::seededProducts();
        $userId = $this->person('Cart with a voucher');
        $group = CartGroup::forUser($userId);
        self::accountLine($userId, $roses, 2);
        $lines = [['productId' => $roses, 'quantity' => 2]];
        $first = $this->createVoucher('CART10', 10);
        $second = $this->createVoucher('CART20', 20);
        $agent = $this->personAgent($userId);
        $this->drainSignals();

        $this->underAgent($agent, fn () => Database::transaction(DatabaseNames::PRIMARY, function () use ($agent): void {
            $agent->applyVoucher('CART10');
            self::assertSame([], $this->cartStates());
        }));
        self::assertSame([[$group, ['userId' => $userId, 'lines' => $lines, 'voucherId' => $first->id]]], $this->cartStates());

        $this->underAgent($agent, static fn () => $agent->applyVoucher('CART20'));
        self::assertSame([[$group, ['userId' => $userId, 'lines' => $lines, 'voucherId' => $second->id]]], $this->cartStates());
        $this->underAgent($agent, static fn () => $agent->applyVoucher('cart20'));
        self::assertSame([], $this->cartStates());

        $this->underAgent($agent, fn () => $this->assertRolledBackWithoutCart($agent->removeVoucher(...)));
        $this->underVoucherLibrary(static fn () => $second->actions->setActive(false));
        $this->assertRefusedWithoutCart(fn () => $this->underAgent($agent, static fn () => $agent->applyVoucher('CART20')));
        $this->askAccountCart($userId);
        self::assertSame([[$group, ['userId' => $userId, 'lines' => $lines, 'voucherId' => $second->id]]], $this->cartStates());

        $this->underAgent($agent, $agent->removeVoucher(...));
        self::assertSame([[$group, ['userId' => $userId, 'lines' => $lines, 'voucherId' => null]]], $this->cartStates());
        $this->underAgent($agent, $agent->removeVoucher(...));
        self::assertSame([], $this->cartStates());
    }

    /** A guest voucher refreshes both subscribed tabs and acknowledges only the initiating request. */
    public function testGuestVoucherApplicationThroughCartPageRefreshesOnlyItsCart(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $sessionId = $this->storedGuestSession();
        $otherSessionId = $this->storedGuestSession();
        $lineId = self::guestLine($sessionId, $productId, 2);
        $voucher = $this->createVoucher('CARTPAGE25', 25);
        $this->startCartWorker();
        $guest = $this->guestCarts();
        $group = CartGroup::forSession($sessionId);
        $otherGroup = CartGroup::forSession($otherSessionId);
        $before = [];
        foreach ([self::FIRST_TAB => $sessionId, self::SECOND_TAB => $sessionId, self::OTHER_TAB => $otherSessionId] as $tab => $id) {
            $this->connectCartTab($tab, null, $id);
            $this->subscribeCart($guest, $tab);
            $before[$tab] = $this->takeCartPage($tab);
            $tabGroup = $id === $sessionId ? $group : $otherGroup;
            Hilos::$sr->subscribeToGroup($tabGroup, new WebSocketGroupSubscribeSignalDTO($tab, $tabGroup));
        }
        self::assertSame([$lineId], array_column($before[self::FIRST_TAB]['lines'], 'lineId'));
        self::assertSame($before[self::FIRST_TAB], $before[self::SECOND_TAB]);
        self::assertNull($before[self::FIRST_TAB]['voucher']);
        self::assertNull($before[self::OTHER_TAB]['voucher']);

        $this->dispatchCartAction($guest, self::cartVoucherSignal('guest-apply', '  CaRtPaGe25  ', CartStepQuantityActionDTO::GUEST));
        [$facts, $states, $reply] = $this->takeCartActionOutcome('guest-apply', self::VOUCHER_ACTION);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertNull($reply->message);
        self::assertNull($reply->reply);
        self::assertCount(1, $facts);
        self::assertInstanceOf(DbSyncCreatedSignalData::class, $facts[0]);
        self::assertSame(EcommerceShopDbContext::sessionVoucherCarts, $facts[0]->collectionKey);
        self::assertSame((string)$sessionId, $facts[0]->idString);
        self::assertSame('guest-apply', $facts[0]->originRequestId);
        self::assertSame($voucher->id, Hilos::$db->sessionVoucherCarts[$sessionId]->voucherId);
        self::assertFalse(isset(Hilos::$db->sessionVoucherCarts[$otherSessionId]));
        self::assertSame([[$group, [
            'userId' => null,
            'lines' => [['productId' => $productId, 'quantity' => 2]],
            'voucherId' => $voucher->id,
        ], [self::FIRST_TAB, self::SECOND_TAB]]], $states);

        $this->flushCartUpdates(...$facts);
        $expected = [
            ...$before[self::FIRST_TAB],
            'voucher' => ['code' => 'CARTPAGE25', 'active' => true, 'discount' => '16000'],
            'total' => '48000',
        ];
        self::assertSame([
            self::FIRST_TAB => $expected,
            self::SECOND_TAB => $expected,
        ], $this->takeCartPages(self::FIRST_TAB, self::SECOND_TAB));

        $this->dispatchCartAction($guest, self::cartVoucherSignal('guest-repeat', 'cartpage25', CartStepQuantityActionDTO::GUEST));
        $this->flushCartUpdates();
        [$facts, $states, $reply] = $this->takeCartActionOutcome('guest-repeat', self::VOUCHER_ACTION);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertNull($reply->message);
        self::assertNull($reply->reply);
        self::assertSame([], $facts);
        self::assertSame([], $states);
        self::assertSame($voucher->id, Hilos::$db->sessionVoucherCarts[$sessionId]->voucherId);
    }

    /** An account keeps its voucher through both catalog refusals and replaces it through the same subscription. */
    public function testAccountVoucherApplicationThroughCartPageKeepsTheOldVoucherOnRefusal(): void
    {
        $userId = $this->person('Voucher shopper');
        $sessionId = $this->storedGuestSession();
        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        self::accountLine($userId, $productId, 1);
        $first = $this->createVoucher('CARTPAGE10', 10);
        $inactive = $this->createVoucher('CARTPAGE20', 20);
        $replacement = $this->createVoucher('CARTPAGE50', 50);
        $this->startCartWorker();
        $owner = $this->personAgent($userId);
        $this->underAgent($owner, static fn () => $owner->applyVoucher($first->code));
        $this->drainSignals();
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        $before = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame(['code' => 'CARTPAGE10', 'active' => true, 'discount' => '3200'], $before['voucher']);
        self::assertSame('28800', $before['total']);

        // Leave the committed catalog update waiting for delivery to this worker.
        self::assertTrue(Hilos::$db->vouchers[$inactive->id]->active);
        Database::transaction(DatabaseNames::PRIMARY, static function () use ($inactive): void {
            Database::sqlRun('UPDATE voucher SET active = FALSE WHERE id = ?', [$inactive->id]);
        });
        self::assertTrue(Hilos::$db->vouchers[$inactive->id]->active);

        foreach ([
            'CARTPAGEUNKNOWN' => 'Voucher code was not found',
            'CARTPAGE20' => 'This voucher is inactive',
        ] as $code => $reason) {
            $this->dispatchCartAction($owner, self::cartVoucherSignal($code, $code, CartStepQuantityActionDTO::ACCOUNT));
            $this->assertCartActionRefused($code, $reason, self::VOUCHER_ACTION);
            self::assertSame($first->id, Hilos::$db->userVoucherCarts[$userId]->voucherId);
        }

        $this->dispatchCartAction($owner, self::cartVoucherSignal('account-apply', 'cartpage50', CartStepQuantityActionDTO::ACCOUNT));
        [$facts, , $reply] = $this->takeCartActionOutcome('account-apply', self::VOUCHER_ACTION);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertNull($reply->message);
        self::assertNull($reply->reply);
        self::assertCount(1, $facts);
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $facts[0]);
        self::assertSame(EcommerceShopDbContext::userVoucherCarts, $facts[0]->collectionKey);
        self::assertSame((string)$userId, $facts[0]->idString);
        self::assertSame($replacement->id, Hilos::$db->userVoucherCarts[$userId]->voucherId);
        $this->flushCartUpdates(...$facts);
        self::assertSame([
            ...$before,
            'voucher' => ['code' => 'CARTPAGE50', 'active' => true, 'discount' => '16000'],
            'total' => '16000',
        ], $this->takeCartPage(self::FIRST_TAB));
    }

    /** A stale owner, pending handoff or mismatched kind cannot write to either cart. */
    public function testVoucherApplicationThroughCartPageRefusesUnconfirmedOwnership(): void
    {
        $sessionId = $this->storedGuestSession();
        $userId = $this->person('Voucher handoff shopper');
        $voucher = $this->createVoucher('CARTPAGE25', 25);
        $this->startCartWorker();
        $guest = $this->guestCarts();
        $this->connectCartTab(self::FIRST_TAB, null, $sessionId);
        $this->subscribeCart($guest, self::FIRST_TAB);
        self::assertNull($this->takeCartPage(self::FIRST_TAB)['voucher']);

        $reason = 'This cart is no longer available.';
        $this->dispatchCartAction($guest, self::cartVoucherSignal('wrong-kind', $voucher->code, CartStepQuantityActionDTO::ACCOUNT));
        $this->assertCartActionRefused('wrong-kind', $reason, self::VOUCHER_ACTION);

        $pending = self::cartVoucherSignal('pending-owner', $voucher->code, CartStepQuantityActionDTO::GUEST);
        $subscription = Hilos::$sr->pageSubscription(self::FIRST_TAB);
        self::assertNotNull($subscription);
        $subscription->awaitingIdentityConfirmation = true;
        try {
            $this->deliverCartFrame($guest, $pending);
            $this->assertCartActionRefused('pending-owner', $reason, self::VOUCHER_ACTION);
        } finally {
            $subscription->awaitingIdentityConfirmation = false;
        }

        Database::sqlRun('UPDATE hilos_session SET token = ? WHERE id = ?', [SessionToken::mint(), $sessionId]);
        $this->dispatchCartAction($guest, self::cartVoucherSignal('rotated-token', $voucher->code, CartStepQuantityActionDTO::GUEST));
        $this->assertCartActionRefused('rotated-token', $reason, self::VOUCHER_ACTION);
        Database::sqlRun('UPDATE hilos_session SET token = ? WHERE id = ?', [Hilos::$db->sessions[$sessionId]->token, $sessionId]);

        $this->rebindStoredSession($sessionId, $userId);
        self::assertNull(Hilos::$rt->connections[self::FIRST_TAB]?->userId);
        $this->dispatchCartAction($guest, self::cartVoucherSignal('stale-owner', $voucher->code, CartStepQuantityActionDTO::GUEST));
        $this->assertCartActionRefused('stale-owner', $reason, self::VOUCHER_ACTION);
        self::assertFalse(isset(Hilos::$db->sessionVoucherCarts[$sessionId]));
        self::assertFalse(isset(Hilos::$db->userVoucherCarts[$userId]));
    }

    /** @param bool $registered Whether the voucher action serves an account or a guest cart */
    #[DataProvider('exactQuantityOwners')]
    public function testVoucherApplicationHoldsTheSessionThroughTheWrite(bool $registered): void
    {
        $sessionId = $this->storedGuestSession();
        $userId = $registered ? $this->person('Voucher session lock holder') : null;
        if ($userId !== null) {
            Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        }
        $voucher = $this->createVoucher('LOCKED25', 25);
        $replacement = $this->createVoucher('LOCKED50', 50);
        $this->startCartWorker();
        $owner = $userId === null ? $this->guestCarts() : $this->personAgent($userId);
        $kind = $userId === null ? CartStepQuantityActionDTO::GUEST : CartStepQuantityActionDTO::ACCOUNT;
        $collection = $userId === null ? EcommerceShopDbContext::sessionVoucherCarts : EcommerceShopDbContext::userVoucherCarts;
        $this->connectCartTab(self::FIRST_TAB, $userId, $sessionId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        self::assertNull($this->takeCartPage(self::FIRST_TAB)['voucher']);

        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $peer = new mysqli($primary->host, $primary->user, $primary->password, $primary->database, $primary->port, $primary->socket);
        $lockSession = static fn () => $peer->execute_query('SELECT id FROM hilos_session WHERE id = ? FOR UPDATE NOWAIT', [$sessionId]);
        $mirror = new class ($collection, (string)($userId ?? $sessionId)) implements SourceMirrorSubscriberInterface {
            public ?Closure $probe = null;

            public function __construct(private readonly string $collection, private readonly string $bindingId)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === $this->collection && $change->sourceId === $this->bindingId
                    && $change->mutationType === TableMutationType::Create
                ) {
                    ($this->probe)?->__invoke();
                }
            }
        };
        $probeErrors = [];
        $mirror->probe = static function () use ($peer, $lockSession, &$probeErrors): void {
            $peer->begin_transaction();
            try {
                $lockSession()->free();
                $probeErrors[] = null;
            } catch (mysqli_sql_exception $failure) {
                $probeErrors[] = $failure->getCode();
            } finally {
                $peer->rollback();
            }
        };
        SourceChangeBus::subscribe($mirror);
        $reason = 'This cart is no longer available.';
        try {
            $this->dispatchCartAction($owner, self::cartVoucherSignal('apply-with-session-lock', $voucher->code, $kind));
            [$facts, , $reply] = $this->takeCartActionOutcome('apply-with-session-lock', self::VOUCHER_ACTION);
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
            self::assertCount(1, $facts);
            self::assertSame([LockWaitTimeoutException::MYSQL_ERROR_CODE], $probeErrors);
            $mirror->probe = null;
            $this->flushCartUpdates(...$facts);
            $this->takeCartPage(self::FIRST_TAB);

            $peer->begin_transaction();
            try {
                $result = $lockSession();
                self::assertSame($sessionId, (int)$result->fetch_column());
                $result->free();
                $this->dispatchCartAction($owner, self::cartVoucherSignal('apply-with-busy-session', $replacement->code, $kind));
                $this->assertCartActionRefused('apply-with-busy-session', $reason, self::VOUCHER_ACTION);
            } finally {
                $peer->rollback();
            }
        } finally {
            $mirror->probe = null;
            $peer->close();
        }

        if ($userId !== null) {
            Database::sqlRun('UPDATE hilos_session SET user_id = NULL WHERE id = ?', [$sessionId]);
            self::assertSame($userId, Hilos::$rt->connections[self::FIRST_TAB]?->userId);
        } else {
            Database::sqlRun('UPDATE hilos_session SET expires_at = ? WHERE id = ?', [TimeHelper::nowMs(), $sessionId]);
        }
        $this->dispatchCartAction($owner, self::cartVoucherSignal('apply-with-stale-session', $replacement->code, $kind));
        $this->assertCartActionRefused('apply-with-stale-session', $reason, self::VOUCHER_ACTION);
        self::assertSame($voucher->id, $userId === null
            ? Hilos::$db->sessionVoucherCarts[$sessionId]->voucherId
            : Hilos::$db->userVoucherCarts[$userId]->voucherId);
    }

    /** A committed line deletion answers the owner's page and group while preserving its voucher. */
    public function testCommittedCartPageRemovalsRefreshOnlyTheirOwner(): void
    {
        $removedProduct = self::seededProduct('pine-bark-5-l');
        $remainingProduct = self::seededProduct('ceramic-pot-18-cm-sand');
        $outOfStockProduct = self::seededProduct('orchid-fertilizer-250-ml');
        $session = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint());
        $sessionId = $session->id ?? self::fail('The guest session was not persisted');
        $this->guestSessionIds[] = $sessionId;
        $neighborSession = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint());
        $neighborSessionId = $neighborSession->id ?? self::fail('The neighbor session was not persisted');
        $this->guestSessionIds[] = $neighborSessionId;
        $userId = $this->person('Cart removal shopper');
        $voucher = $this->createVoucher('CART10', 10);
        $guestLine = self::guestLine($sessionId, $removedProduct, 2);
        $remainingLine = self::guestLine($sessionId, $remainingProduct, 1);
        $neighborLine = self::guestLine($neighborSessionId, $removedProduct, 3);
        $accountLine = self::accountLine($userId, $outOfStockProduct, 2);
        $guest = $this->guestCarts();
        $account = $this->personAgent($userId);
        $this->underAgent($guest, static fn () => $guest->applyVoucher($sessionId, 'CART10'));
        $this->underAgent($account, static fn () => $account->applyVoucher('CART10'));
        $this->drainSignals();

        $this->underAgent($guest, fn () => $this->assertRolledBackWithoutCart(
            static fn () => $guest->removeProductLine($sessionId, $guestLine),
        ));
        [$before, $after, $states] = $this->removeThroughCartPage($guest, $sessionId, null, $guestLine, $guest, $neighborSessionId, $neighborLine);
        self::assertSame([$guestLine, $remainingLine], array_column($before['lines'], 'lineId'));
        self::assertSame('61000', $before['itemsTotal']);
        self::assertSame('54900', $before['total']);
        self::assertNull($after['userId']);
        self::assertNotEmpty($before['guestCartKey']);
        self::assertSame($before['guestCartKey'], $after['guestCartKey']);
        self::assertSame([$remainingLine], array_column($after['lines'], 'lineId'));
        self::assertSame([$remainingProduct], array_column($after['lines'], 'productId'));
        self::assertSame('32000', $after['itemsTotal']);
        self::assertSame(['code' => 'CART10', 'active' => true, 'discount' => '3200'], $after['voucher']);
        self::assertSame('28800', $after['total']);
        self::assertSame([[CartGroup::forSession($sessionId), [
            'userId' => null,
            'lines' => [['productId' => $remainingProduct, 'quantity' => 1]],
            'voucherId' => $voucher->id,
        ]]], $states);
        self::assertNull(Database::sql('SELECT id FROM session_product_cart WHERE id = ?', [$guestLine])->field('id'));
        self::assertSame($voucher->id, Hilos::$db->sessionVoucherCarts[$sessionId]->voucherId);

        $neighborUserId = $this->person('Neighboring cart shopper');
        $neighborAccountLine = self::accountLine($neighborUserId, $outOfStockProduct, 1);
        $neighborAccount = $this->personAgent($neighborUserId);
        $session->actions->bindUser($userId);
        $neighborSession->actions->bindUser($neighborUserId);
        [$before, $after, $states] = $this->removeThroughCartPage(
            $account,
            $sessionId,
            $userId,
            $accountLine,
            $neighborAccount,
            $neighborSessionId,
            $neighborAccountLine,
            $neighborUserId,
        );
        self::assertSame([$accountLine], array_column($before['lines'], 'lineId'));
        self::assertSame([0], array_column($before['lines'], 'availableQuantity'));
        self::assertSame([
            'userId' => $userId,
            'guestCartKey' => null,
            'lines' => [],
            'itemsTotal' => '0',
            'voucher' => ['code' => 'CART10', 'active' => true, 'discount' => '0'],
            'total' => '0',
        ], $after);
        self::assertSame([[CartGroup::forUser($userId), [
            'userId' => $userId,
            'lines' => [],
            'voucherId' => $voucher->id,
        ]]], $states);
        self::assertNull(Database::sql('SELECT id FROM user_product_cart WHERE id = ?', [$accountLine])->field('id'));
        self::assertSame($voucher->id, Hilos::$db->userVoucherCarts[$userId]->voucherId);
        self::assertSame(3, (int)Database::sql('SELECT quantity FROM session_product_cart WHERE id = ?', [$neighborLine])->field('quantity'));
        self::assertSame(1, (int)Database::sql('SELECT quantity FROM user_product_cart WHERE id = ?', [$neighborAccountLine])->field('quantity'));
    }

    /**
     * @param AgentInterface $owner Owner of the current cart
     * @param int $sessionId Session reported by the connection
     * @param ?int $userId Person reported by the connection
     * @param int $lineId Cart line read by the page
     * @param AgentInterface $neighborOwner Owner of the neighboring cart
     * @param int $neighborSessionId Session subscribed beside the acting connection
     * @param int $neighborLineId Line in the neighboring cart
     * @param ?int $neighborUserId Person on the neighboring connection, or null for a guest
     * @return array{array<string, mixed>, array<string, mixed>, list<array{string, array<string, mixed>}>}
     *     Initial cart page, refreshed cart page and emitted cart states
     */
    private function removeThroughCartPage(
        AgentInterface $owner,
        int $sessionId,
        ?int $userId,
        int $lineId,
        AgentInterface $neighborOwner,
        int $neighborSessionId,
        int $neighborLineId,
        ?int $neighborUserId = null,
    ): array {
        $acceptKey = 'cart-state-removal';
        $neighborKey = 'cart-state-neighbor';
        $previousBrowser = Hilos::$browser;
        $previousRouter = Hilos::$sr;
        Hilos::initBrowser();
        $worker = new EcommerceShopWorkerManager(1);
        Hilos::$browser->bindPageResender($worker);
        $agents = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($worker);
        $agents->addAgent($owner->getId(), $owner);
        $agents->addAgent($neighborOwner->getId(), $neighborOwner);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), $acceptKey);
        try {
            return ExecutionContext::run(new ExecutionFrame(), function () use (
                $worker, $owner, $acceptKey, $sessionId, $userId, $lineId,
                $neighborOwner, $neighborKey, $neighborSessionId, $neighborLineId, $neighborUserId,
            ): array {
                $before = $this->subscribeForRemoval($worker, $owner, $acceptKey, $sessionId, $userId);
                $neighbor = $this->subscribeForRemoval($worker, $neighborOwner, $neighborKey, $neighborSessionId, $neighborUserId);
                self::assertSame($neighborUserId, $neighbor['userId']);
                self::assertSame([$neighborLineId], array_column($neighbor['lines'], 'lineId'));

                $removal = new CartRemoveLineActionDTO(
                    $lineId,
                    $userId === null ? CartRemoveLineActionDTO::GUEST : CartRemoveLineActionDTO::ACCOUNT,
                );
                $send = static function (string $requestId) use ($worker, $owner, $acceptKey, $removal): void {
                    $action = new SignalDTO(
                        new SignalSource(SignalSource::WEBSOCKET),
                        new SignalType(SignalTypeConstants::ACTION),
                        new SignalName($removal->getAction()),
                        new WebSocketActionSignalDTO($acceptKey, $removal->getAction(), $removal->toArray(), $requestId),
                    );
                    self::assertEquals([new AgentDestination($owner->getType(), $owner->getIndex())], Hilos::$sr->getDestinations($action));
                    $worker->handleDaemonMessage(new DaemonAgentMessageDTO($owner->getId(), $action));
                };
                $requestId = 'remove';
                $send($requestId);
                [$deleted, $states] = $this->takeCommittedRemoval($acceptKey, $requestId);
                self::assertSame(
                    $userId === null ? EcommerceShopDbContext::sessionProductCarts : EcommerceShopDbContext::userProductCarts,
                    $deleted->collectionKey,
                );
                self::assertSame((string)$lineId, $deleted->idString);
                $ownerField = $userId === null ? ObjectSessionProductCart::sessionId : ObjectUserProductCart::userId;
                self::assertSame($userId ?? $sessionId, $deleted->row[$ownerField] ?? null);

                self::assertSame(CartPage::PAGE, Hilos::$sr->pageSubscription($acceptKey)?->page);
                self::assertSame(CartPage::PAGE, Hilos::$sr->pageSubscription($neighborKey)?->page);
                // Pass the queued delete to the same browser source entry used by the local worker drain.
                new ReflectionMethod(WorkerManager::class, 'recordBrowserSourceChange')->invoke($worker, $deleted);
                self::assertSame([], Hilos::$browser->flushToSignalRouter());
                $after = $this->takeCartPage($acceptKey);

                $send('try-again');
                self::assertSame([], $this->cartStates());

                return [$before, $after, $states];
            });
        } finally {
            ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey, $neighborKey): void {
                foreach ([$acceptKey, $neighborKey] as $connectionKey) {
                    Hilos::$rt->connections[$connectionKey]?->actions->unregister();
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::page($connectionKey));
                }
            });
            RtTruthSourceRegistry::unregisterAgent($acceptKey);
            Hilos::$sr = $previousRouter;
            if ($previousBrowser === null) {
                Hilos::resetBrowser();
            } else {
                Hilos::initBrowser($previousBrowser);
            }
        }
    }

    /**
     * @param WorkerManager $worker Worker serving the subscription
     * @param AgentInterface $owner Cart owner
     * @param string $acceptKey Connection subscribing to the cart
     * @param int $sessionId Saved session on the connection
     * @param ?int $userId Account on the connection, or null for a guest
     * @return array<string, mixed> Cart snapshot delivered by the subscription
     */
    private function subscribeForRemoval(WorkerManager $worker, AgentInterface $owner, string $acceptKey, int $sessionId, ?int $userId): array
    {
        $this->connectCartTab($acceptKey, $userId, $sessionId);
        $worker->handleDaemonMessage(new DaemonAgentMessageDTO($owner->getId(), new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(CartPage::PAGE),
            new WebSocketPageSubscribeSignalDTO($acceptKey, CartPage::PAGE),
        )));

        return $this->takeCartPage($acceptKey);
    }

    /**
     * @param string $acceptKey Only connection expecting a cart page frame
     * @return array<string, mixed> Complete cart from that reply
     */
    private function takeCartPage(string $acceptKey): array
    {
        return $this->takeCartPages($acceptKey)[$acceptKey];
    }

    /**
     * @param string ...$acceptKeys Connections expecting exactly one complete cart each
     * @return array<string, array<string, mixed>> Complete carts keyed by destination
     */
    private function takeCartPages(string ...$acceptKeys): array
    {
        $pages = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(EcommerceShopSignalConstants::CART_STATE, $signal->signalName->getName());
            $frame = $signal->data;
            if ($frame instanceof WebSocketSignalData
                && ($frame->data instanceof PageResponseSignalData || $frame->data instanceof PageSubscriptionErrorSignalData)
            ) {
                self::assertNotNull($frame->targetAcceptKey);
                self::assertArrayNotHasKey($frame->targetAcceptKey, $pages);
                self::assertInstanceOf(PageResponseSignalData::class, $frame->data);
                self::assertSame(CartPage::PAGE, $frame->data->pageKey);
                $wire = PageResponseSignalData::fromArray($frame->data->toArray())->payload->data;
                self::assertSame([CartPage::DATA_CART], array_keys($wire));
                $pages[$frame->targetAcceptKey] = $wire[CartPage::DATA_CART];
            }
        }
        self::assertEqualsCanonicalizing($acceptKeys, array_keys($pages));

        return $pages;
    }

    /**
     * @param string $acceptKey Connection that asked to remove the line
     * @param string $requestId Request behind the committed write
     * @return array{DbSyncDeletedSignalData, list<array{string, array<string, mixed>}>} The delete and group frames from the action
     */
    private function takeCommittedRemoval(string $acceptKey, string $requestId): array
    {
        $deletes = [];
        $replies = [];
        $states = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frame = $signal->data;
            if ($signal->signalType->getType() === SignalTypeConstants::DB_SYNC_DELETED) {
                self::assertInstanceOf(DbSyncDeletedSignalData::class, $frame);
                // The dispatcher can delete its empty change log receipt after the action.
                if ($frame->collectionKey === HilosDbContext::changeLogReceipts) {
                    continue;
                }
                self::assertSame($acceptKey, $frame->origin);
                self::assertSame($requestId, $frame->originRequestId);
                $deletes[] = $frame;
            } elseif ($frame instanceof WebSocketSignalData) {
                self::assertNotInstanceOf(PageResponseSignalData::class, $frame->data);
                self::assertNotInstanceOf(PageSubscriptionErrorSignalData::class, $frame->data);
                if ($signal->signalName->getName() === EcommerceShopSignalConstants::CART_STATE) {
                    self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
                    $states[] = self::cartState($frame);
                } elseif ($frame->data instanceof PageActionSuccessSignalData || $frame->data instanceof PageActionErrorSignalData) {
                    self::assertSame($acceptKey, $frame->targetAcceptKey);
                    self::assertInstanceOf(PageActionSuccessSignalData::class, $frame->data);
                    self::assertSame(EcommerceShopSignalConstants::CART_REMOVE_LINE, $frame->data->action);
                    self::assertSame($requestId, $frame->data->requestId);
                    self::assertNull($frame->data->reply);
                    $replies[] = $frame;
                }
            }
        }
        self::assertCount(1, $deletes);
        self::assertCount(1, $replies);

        return [$deletes[0], $states];
    }

    /** @param callable(): void $change Cart change to roll back */
    private function assertRolledBackWithoutCart(callable $change): void
    {
        $rollback = new RuntimeException('Roll back the cart change');
        try {
            Database::transaction(DatabaseNames::PRIMARY, function () use ($change, $rollback): void {
                $change();
                self::assertSame([], $this->cartStates());

                throw $rollback;
            });
            self::fail('The cart change committed');
        } catch (RuntimeException $failure) {
            self::assertSame($rollback, $failure);
        }
        self::assertSame([], $this->cartStates());
    }

    /** @param callable(): void $apply Apply an inactive voucher */
    private function assertRefusedWithoutCart(callable $apply): void
    {
        try {
            $apply();
            self::fail('The inactive voucher was accepted');
        } catch (ValidationException $failure) {
            self::assertSame('This voucher is inactive', $failure->getMessage());
            self::assertSame([], $this->cartStates());
        }
    }

    /**
     * @param string $code Voucher code
     * @param int $discountPercent Whole discount percentage
     * @return Voucher Saved active voucher fixture
     */
    private function createVoucher(string $code, int $discountPercent): Voucher
    {
        $voucher = $this->underVoucherLibrary(static fn () => Hilos::$db->vouchers->actions->create($code, $discountPercent, true));
        $this->voucherIds[] = $voucher->id ?? self::fail('The voucher was not persisted');

        return $voucher;
    }

    /** @return int Persisted guest session registered for case cleanup */
    private function storedGuestSession(): int
    {
        $sessionId = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint())->id
            ?? self::fail('The guest session was not persisted');
        $this->guestSessionIds[] = $sessionId;

        return $sessionId;
    }

    /**
     * Changes the saved owner before the session and connection mirrors receive the change.
     *
     * @param int $sessionId Session whose object is already loaded
     * @param int $userId New persisted owner
     */
    private function rebindStoredSession(int $sessionId, int $userId): void
    {
        Database::sql('UPDATE hilos_session SET user_id = ? WHERE id = ?', [$userId, $sessionId]);
    }

    /** Mounts the worker and source fan-out for the tests that dispatch cart page actions. */
    private function startCartWorker(): void
    {
        $this->previousBrowser = Hilos::$browser;
        Hilos::initBrowser();
        $this->cartWorker = new EcommerceShopWorkerManager(1);
        Hilos::$browser->bindPageResender($this->cartWorker);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
    }

    /** Releases the cart action tabs and restores the browser context. */
    private function releaseCartWorker(): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(), static function (): void {
                foreach ([self::FIRST_TAB, self::SECOND_TAB, self::OTHER_TAB] as $acceptKey) {
                    Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
                }
            });
        } finally {
            RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
            if ($this->previousBrowser === null) {
                Hilos::resetBrowser();
            } else {
                Hilos::initBrowser($this->previousBrowser);
            }
        }
    }

    /**
     * @param string $acceptKey Browser tab
     * @param ?int $userId User of the tab, null for a guest
     * @param ?int $sessionId Session of the tab, null when the connection has none
     */
    private function connectCartTab(string $acceptKey, ?int $userId, ?int $sessionId): void
    {
        $token = $sessionId === null ? null : Hilos::$db->sessions[$sessionId]->token;
        ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey, $userId, $sessionId, $token): void {
            Hilos::$rt->connections->actions->register($acceptKey, $userId, $token, $sessionId);
        });
    }

    /**
     * @param AgentInterface $agent Current owner of the cart
     * @param string $acceptKey Browser tab
     */
    private function subscribeCart(AgentInterface $agent, string $acceptKey): void
    {
        $agents = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($this->cartWorker);
        $agents->addAgent($agent->getId(), $agent);
        $this->deliverCartFrame($agent, self::webSocketFrame(
            SignalTypeConstants::PAGE_SUBSCRIBE, CartPage::PAGE, new WebSocketPageSubscribeSignalDTO($acceptKey, CartPage::PAGE),
        ));
    }

    /**
     * @param AgentInterface $owner Expected destination of the subscribed action
     * @param string $requestId Request correlation
     * @param int $lineId Saved line PK
     * @param string $kind Owner kind captured by the caller
     * @param int $step One unit up or down
     */
    private function dispatchCartStep(AgentInterface $owner, string $requestId, int $lineId, string $kind, int $step): void
    {
        $this->dispatchCartAction($owner, self::cartStepSignal($requestId, $lineId, $kind, $step));
    }

    /**
     * @param AgentInterface $owner Expected destination of the subscribed action
     * @param string $requestId Request correlation
     * @param int $lineId Saved line PK
     * @param string $kind Owner kind captured by the caller
     * @param string $quantity Original quantity text
     * @param int $expectedQuantity Saved quantity the edit started from
     */
    private function dispatchCartSetQuantity(
        AgentInterface $owner,
        string $requestId,
        int $lineId,
        string $kind,
        string $quantity,
        int $expectedQuantity,
    ): void {
        $this->dispatchCartAction($owner, self::cartSetQuantitySignal($requestId, $lineId, $kind, $quantity, $expectedQuantity));
    }

    /**
     * @param AgentInterface $owner Expected destination of the subscribed action
     * @param SignalDTO $signal Browser action to route
     */
    private function dispatchCartAction(AgentInterface $owner, SignalDTO $signal): void
    {
        self::assertEquals([new AgentDestination($owner->getType(), $owner->getIndex())], Hilos::$sr->getDestinations($signal));
        $this->deliverCartFrame($owner, $signal);
    }

    /**
     * @param string $requestId Request correlation
     * @param int $lineId Saved line PK
     * @param string $kind Owner kind captured by the caller
     * @param int $step One unit up or down
     * @return SignalDTO Browser action for the production dispatcher
     */
    private static function cartStepSignal(string $requestId, int $lineId, string $kind, int $step): SignalDTO
    {
        return self::webSocketFrame(SignalTypeConstants::ACTION, self::STEP_ACTION, new WebSocketActionSignalDTO(
            self::FIRST_TAB, self::STEP_ACTION, ['lineId' => $lineId, 'ownerKind' => $kind, 'step' => $step], $requestId,
        ));
    }

    /**
     * @param string $requestId Request correlation
     * @param int $lineId Saved line PK
     * @param string $kind Owner kind captured by the caller
     * @param string $quantity Original quantity text
     * @param int $expectedQuantity Saved quantity the edit started from
     * @return SignalDTO Browser action for the production dispatcher
     */
    private static function cartSetQuantitySignal(string $requestId, int $lineId, string $kind, string $quantity, int $expectedQuantity): SignalDTO
    {
        return self::webSocketFrame(SignalTypeConstants::ACTION, self::SET_ACTION, new WebSocketActionSignalDTO(
            self::FIRST_TAB,
            self::SET_ACTION,
            ['lineId' => $lineId, 'ownerKind' => $kind, 'quantity' => $quantity, 'expectedQuantity' => $expectedQuantity],
            $requestId,
        ));
    }

    /**
     * @param string $requestId Request correlation
     * @param string $code Code entered by the shopper
     * @param string $kind Owner kind captured by the caller
     * @return SignalDTO Browser action for the production dispatcher
     */
    private static function cartVoucherSignal(string $requestId, string $code, string $kind): SignalDTO
    {
        return self::webSocketFrame(SignalTypeConstants::ACTION, self::VOUCHER_ACTION, new WebSocketActionSignalDTO(
            self::FIRST_TAB, self::VOUCHER_ACTION, ['code' => $code, 'ownerKind' => $kind], $requestId,
        ));
    }

    /**
     * @param string $type Signal type
     * @param string $name Page or action
     * @param SignalDataInterface $data Frame from the browser
     * @return SignalDTO Browser signal wrapped for routing
     */
    private static function webSocketFrame(string $type, string $name, SignalDataInterface $data): SignalDTO
    {
        return new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType($type), new SignalName($name), $data);
    }

    /**
     * @param AgentInterface $agent Addressed agent
     * @param SignalDTO $signal Routed frame from the browser
     */
    private function deliverCartFrame(AgentInterface $agent, SignalDTO $signal): void
    {
        ExecutionContext::run(new ExecutionFrame(), fn () => $this->cartWorker->handleDaemonMessage(
            new DaemonAgentMessageDTO($agent->getId(), $signal),
        ));
    }

    /**
     * @param string $requestId Request expected to receive the sole personal reply
     * @param string $action Cart action expected in the reply
     * @return array{list<DbSyncCreatedSignalData|DbSyncUpdatedSignalData>, list<array{string, array<string, mixed>, list<string>}>,
     *     PageActionSuccessSignalData|PageActionErrorSignalData} Persisted facts, group delivery and ack
     */
    private function takeCartActionOutcome(string $requestId, string $action = self::STEP_ACTION): array
    {
        $facts = [];
        $states = [];
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frame = $signal->data;
            if ($frame instanceof DbSyncUpdatedSignalData || ($action === self::VOUCHER_ACTION && $frame instanceof DbSyncCreatedSignalData
                && in_array($frame->collectionKey, [EcommerceShopDbContext::sessionVoucherCarts, EcommerceShopDbContext::userVoucherCarts], true)
            )) {
                $facts[] = $frame;
            } elseif ($frame instanceof DbSyncSignalDataInterface || $frame instanceof DbSyncClearedSignalData) {
                self::assertNotContains($frame->collectionKey, [
                    EcommerceShopDbContext::sessionProductCarts,
                    EcommerceShopDbContext::sessionVoucherCarts,
                    EcommerceShopDbContext::userProductCarts,
                    EcommerceShopDbContext::userVoucherCarts,
                ]);
            }
            if (!$frame instanceof WebSocketSignalData) {
                continue;
            }
            self::assertNotInstanceOf(PageResponseSignalData::class, $frame->data);
            if ($frame->data instanceof PageActionSuccessSignalData || $frame->data instanceof PageActionErrorSignalData) {
                self::assertSame(self::FIRST_TAB, $frame->targetAcceptKey);
                [$expectedSignal, $outcome] = $frame->data instanceof PageActionSuccessSignalData
                    ? [SignalConstants::ACTION_SUCCESS, 'success']
                    : [SignalConstants::ACTION_ERROR, 'fail'];
                self::assertSame($expectedSignal, $signal->signalName->getName());
                self::assertSame($action, $frame->data->action);
                self::assertSame($requestId, $frame->data->requestId);
                self::assertSame($outcome, $frame->data->getEnvelopeOutcome());
                self::assertSame($requestId, $frame->data->getEnvelopeRequestId());
                $replies[] = $frame->data;
            }
            if ($frame->data instanceof CartStateSignalData) {
                self::assertSame(EcommerceShopSignalConstants::CART_STATE, $signal->signalName->getName());
                self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
                $states[] = [...self::cartState($frame), array_map(
                    static fn (WebSocketDestination $target): string => $target->acceptKey,
                    Hilos::$sr->getDestinations($signal),
                )];
            }
        }
        self::assertCount(1, $replies);

        return [$facts, $states, $replies[0]];
    }

    /**
     * @param string $requestId Refused action
     * @param string $reason Server's reason for refusing
     * @param string $action Cart action expected in the reply
     */
    private function assertCartActionRefused(string $requestId, string $reason, string $action = self::STEP_ACTION): void
    {
        $this->flushCartUpdates();
        [$facts, $states, $reply] = $this->takeCartActionOutcome($requestId, $action);
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
        self::assertSame($reason, $reply->reason);
        self::assertSame([], $facts);
        self::assertSame([], $states);
    }

    /**
     * @param string $table Cart line table supplied by its entity
     * @param int $lineId Saved line PK
     * @return int Quantity in the database
     */
    private static function storedQuantity(string $table, int $lineId): int
    {
        return (int)Database::sql("SELECT quantity FROM `{$table}` WHERE id = ?", [$lineId])->field('quantity');
    }

    /**
     * @param int $productId Fixture product stocked in one warehouse
     * @param int $units Lowered stock units
     * @return DbSyncUpdatedSignalData Fact to deliver after the stock change commits
     */
    private function lowerStock(int $productId, int $units): DbSyncUpdatedSignalData
    {
        $stock = Database::sql('SELECT id, units FROM product_warehouse_stock WHERE product_id = ?', [$productId])->rows();
        self::assertCount(1, $stock);
        $stockId = (int)$stock[0]['id'];
        $this->previousStock = ['id' => $stockId, 'units' => (int)$stock[0]['units']];
        Database::sql('UPDATE product_warehouse_stock SET units = ? WHERE id = ?', [$units, $stockId]);

        return new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::productWarehouseStocks, (string)$stockId, [ProductWarehouseStock::units => $units],
        );
    }

    /** @param DbSyncCreatedSignalData|DbSyncUpdatedSignalData ...$facts Committed changes as received from a peer worker */
    private function flushCartUpdates(DbSyncCreatedSignalData|DbSyncUpdatedSignalData ...$facts): void
    {
        ExecutionContext::run(new ExecutionFrame(), function () use ($facts): void {
            foreach ($facts as $fact) {
                $this->cartWorker->handleDaemonMessage($fact instanceof DbSyncCreatedSignalData
                    ? new WorkerDbSyncCreatedMessageDTO($fact->withEmitter('cart-step-peer'))
                    : new WorkerDbSyncUpdatedMessageDTO($fact->withEmitter('cart-step-peer')));
            }
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
        });
    }

    /**
     * @template T
     * @param callable(): T $change Fixture catalog write
     * @return T Result of the write
     */
    private function underVoucherLibrary(callable $change): mixed
    {
        $library = new VouchersLibraryAgent();
        try {
            OwnershipDeclaration::claimAll($library);

            return $this->underAgent($library, $change);
        } finally {
            TruthSourceRegistry::unregisterAgent($library->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($library->getId()));
        }
    }

    /** @param int $userId Person whose cart is asked for */
    private function askAccountCart(int $userId): void
    {
        $agent = $this->personAgent($userId);
        $this->underAgent($agent, static fn () => $agent->onSignalAgent(
            new AgentSignalData(new AccountCartSendSignalData($userId)),
            '',
            EcommerceShopSignalConstants::ACCOUNT_CART_SEND,
        ));
    }

    /**
     * Hands the guest carts library the ask the connections agent sends for a session.
     *
     * @param int $sessionId Row of the session whose cart is asked for
     */
    private function askGuestCart(int $sessionId): void
    {
        $carts = $this->guestCarts();
        $this->underAgent($carts, static fn () => $carts->onSignalAgent(
            new AgentSignalData(new GuestCartSendSignalData($sessionId)),
            '',
            EcommerceShopSignalConstants::GUEST_CART_SEND,
        ));
    }

    /**
     * Starts the guest carts library the way its worker does, once per case: its claims, then its start.
     *
     * @return GuestCartsLibraryAgent The library, started
     */
    private function guestCarts(): GuestCartsLibraryAgent
    {
        if ($this->carts === null) {
            $this->carts = new GuestCartsLibraryAgent();
            $carts = $this->carts;
            $this->underAgent($carts, fn () => $this->startAgent($carts));
        }

        return $this->carts;
    }

    /**
     * Drains the queue and returns every cart frame sent to a group, in order.
     *
     * @return list<array{string, array<string, mixed>}> Each frame's group and its payload as the wire carries it
     */
    private function cartStates(): array
    {
        $states = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== EcommerceShopSignalConstants::CART_STATE) {
                continue;
            }
            self::assertSame(SignalTypeConstants::WS_GROUP, $signal->signalType->getType());
            $data = $signal->data;
            self::assertInstanceOf(WebSocketSignalData::class, $data);
            $states[] = self::cartState($data);
        }

        return $states;
    }

    /**
     * @param string $token Session token sent to the command socket
     * @param int $productId Product to change
     * @param int $quantity Requested quantity
     * @return array{CommandReplyDTO, list<array{string, array<string, mixed>}>} Command outcome and group frames
     */
    private function guestFixture(string $token, int $productId, int $quantity): array
    {
        $carts = $this->guestCarts();
        $this->drainSignals();
        $name = EcommerceShopCommandConstants::GUEST_CART_SET_QUANTITY;
        $query = new GuestCartSetQuantityCommandData($token, $productId, $quantity);
        $request = new CommandRequestDTO('guest-fixture', $name, $query->toArray());
        $parsed = Hilos::$sr->createCommandPayloadDTO($name, $request);
        $this->underAgent($carts, static fn () => $carts->onSignalCommand($parsed, '', $name));

        $states = [];
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() === SignalTypeConstants::COMMAND_REPLY) {
                self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
                self::assertSame($request->correlationId, $signal->data->correlationId);
                $replies[] = $signal->data;
            } elseif ($signal->signalName->getName() === EcommerceShopSignalConstants::CART_STATE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                $states[] = self::cartState($signal->data);
            }
        }
        self::assertCount(1, $replies);

        return [$replies[0], $states];
    }

    /**
     * @param string $token Token used by the command
     * @param int $productId Product to change
     * @param int $quantity Requested quantity
     * @param string $reason Expected refusal
     */
    private function assertFixtureRefused(string $token, int $productId, int $quantity, string $reason): void
    {
        [$reply, $frames] = $this->guestFixture($token, $productId, $quantity);
        self::assertFalse($reply->isOk());
        self::assertSame([CommandConstants::FIELD_MESSAGE => $reason], $reply->payload);
        self::assertSame([], $frames);
    }

    /**
     * @param WebSocketSignalData $frame Cart state delivered to a group
     * @return array{string, array<string, mixed>} Address and payload on the wire
     */
    private static function cartState(WebSocketSignalData $frame): array
    {
        self::assertInstanceOf(CartStateSignalData::class, $frame->data);

        return [$frame->targetGroup, $frame->data->toArray()];
    }

    /**
     * @param string $slug Address from the seeded catalog
     * @return int Seeded product ID
     */
    private static function seededProduct(string $slug): int
    {
        $id = Database::sql('SELECT id FROM product WHERE slug = ?', [$slug])->field('id');
        self::assertNotNull($id);

        return (int)$id;
    }

    /**
     * Creates a person through the person table's writer, removed after the case.
     *
     * @param string $name Name of the person
     * @return int Id of the new person
     */
    private function person(string $name): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName($name)->id;
        $this->userIds[] = $userId;

        return $userId;
    }

    /**
     * @return list<int> Ids of two products of the seeded catalog
     */
    private static function seededProducts(): array
    {
        $products = array_map(
            static fn (array $row): int => (int)$row['id'],
            Database::sql('SELECT `id` FROM `product` ORDER BY `id` LIMIT 2')->rows(),
        );
        self::assertCount(2, $products, 'The seeded catalog is reset with the test database');

        return $products;
    }

    /**
     * @param int $sessionId Session owning the line
     * @param int $productId Product in the line
     * @return ?int Stored quantity, or null when the exact pair has no row
     */
    private static function storedGuestQuantity(int $sessionId, int $productId): ?int
    {
        $quantity = Database::sql(
            'SELECT quantity FROM session_product_cart WHERE session_id = ? AND product_id = ?',
            [$sessionId, $productId],
        )->field('quantity');

        return $quantity === null ? null : (int)$quantity;
    }

    /**
     * Writes a line of a guest's cart past the guest carts library.
     *
     * @param int $sessionId Row of the session whose cart it is
     * @param int $productId Product of the line
     * @param int $quantity Units the line holds, one at least
     * @return int Saved line ID
     */
    private static function guestLine(int $sessionId, int $productId, int $quantity): int
    {
        Database::sql(
            'INSERT INTO `session_product_cart` (`session_id`, `product_id`, `quantity`) VALUES (?, ?, ?)',
            [$sessionId, $productId, $quantity],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a line of a person's cart past the person's agent.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Product of the line
     * @param int $quantity Units the line holds, one at least
     * @return int Saved line ID
     */
    private static function accountLine(int $userId, int $productId, int $quantity): int
    {
        Database::sql(
            'INSERT INTO `user_product_cart` (`user_id`, `product_id`, `quantity`) VALUES (?, ?, ?)',
            [$userId, $productId, $quantity],
        );

        return Database::lastInsertId();
    }
}
