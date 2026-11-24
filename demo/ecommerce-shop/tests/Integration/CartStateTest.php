<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

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
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CartPage;
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
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\DatabaseNames;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionProperty;
use RuntimeException;

/**
 * What the owners of the carts tell the cart groups: the whole cart, after the commit.
 *
 * Real commits: an owner sends the cart once its transaction commits, and a rollback-scoped case
 * discards what waits for that commit, so the frames are read here and not beside the cart
 * actions. The guest carts library and the person's agent start the way their workers start
 * them; the lines are written past them, before the library reads the carts in whole, and the
 * person's agent reads its person's lines from the database. The products are the seeded
 * catalog's. A case leaves nothing behind: its lines and its people are removed after it, and
 * the memory that read them is forgotten. Voucher cases also create and remove their own fixtures.
 */
final class CartStateTest extends IntegrationTestCase
{
    private const int SESSION = 101;
    private const int OTHER_SESSION = 102;
    private const string FIRST_TAB = 'cart-step-first';
    private const string OTHER_TAB = 'cart-step-other';
    private const string CONNECTIONS_OWNER = 'cart-step-test';
    private const string STEP_ACTION = EcommerceShopSignalConstants::CART_STEP_QUANTITY;
    private const string LINE_UNAVAILABLE = 'This cart item is no longer available.';

    /** @var list<int> People the case created, removed after it */
    private array $userIds = [];

    /** @var list<int> Catalog vouchers the case created, removed after it */
    private array $voucherIds = [];

    private ?int $guestSessionId = null;

    private ?GuestCartsLibraryAgent $carts = null;
    private ?EcommerceShopWorkerManager $cartWorker = null;
    private ?BrowserContext $previousBrowser = null;

    /** @var array{id: int, units: int}|null Stock row changed by the account step case */
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
            if ($this->guestSessionId !== null) {
                Database::sqlRun('DELETE FROM `session_product_cart` WHERE `session_id` = ?', [$this->guestSessionId]);
                Database::sqlRun('DELETE FROM `session_voucher_cart` WHERE `session_id` = ?', [$this->guestSessionId]);
                Database::sqlRun('DELETE FROM `hilos_session` WHERE `id` = ?', [$this->guestSessionId]);
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
            $this->guestSessionId = null;
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
        $lineId = self::guestLine($sessionId, $productId, 2);
        $otherLineId = self::guestLine(self::OTHER_SESSION, $productId, 1);
        $voucher = $this->createVoucher('CARTSTEP10', 10);
        Database::sql('INSERT INTO session_voucher_cart (session_id, voucher_id) VALUES (?, ?)', [$sessionId, $voucher->id]);
        $this->startCartWorker();
        $guest = $this->guestCarts();
        $this->connectCartTab(self::FIRST_TAB, null, $sessionId);
        $this->connectCartTab(self::OTHER_TAB, null, self::OTHER_SESSION);
        $this->subscribeCart($guest, self::FIRST_TAB);
        $initial = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([$lineId], array_column($initial['lines'], 'lineId'));
        $this->subscribeCart($guest, self::OTHER_TAB);
        self::assertSame([$otherLineId], array_column($this->takeCartPage(self::OTHER_TAB)['lines'], 'lineId'));
        $group = CartGroup::forSession($sessionId);
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(self::FIRST_TAB, $group));
        $otherGroup = CartGroup::forSession(self::OTHER_SESSION);
        Hilos::$sr->subscribeToGroup($otherGroup, new WebSocketGroupSubscribeSignalDTO(self::OTHER_TAB, $otherGroup));

        foreach ([CartLineRules::STEP_UP => 3, CartLineRules::STEP_DOWN => 2] as $step => $quantity) {
            $requestId = $step === CartLineRules::STEP_UP ? 'guest-plus' : 'guest-minus';
            $this->dispatchCartStep($guest, $requestId, $lineId, CartStepQuantityActionDTO::GUEST, $step);
            [$facts, $states, $reply] = $this->takeStepOutcome($requestId);
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
        $this->assertStepRefused('account-plus', 'Only 2 in stock');
        self::assertSame(5, self::storedQuantity(UserProductCart::_table, $lineId));

        $this->flushCartUpdates($stockFact);
        self::assertSame(2, $this->takeCartPage(self::FIRST_TAB)['lines'][0]['availableQuantity']);

        $this->dispatchCartStep($owner, 'account-minus', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_DOWN);
        [$facts, $states, $reply] = $this->takeStepOutcome('account-minus');
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

        Database::sql('UPDATE hilos_session SET user_id = ? WHERE id = ?', [$otherUserId, $sessionId]);
        self::assertSame($userId, Hilos::$db->sessions[$sessionId]->userId);

        $this->dispatchCartStep($owner, 'account-rebound', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_DOWN);
        $this->assertStepRefused('account-rebound', self::LINE_UNAVAILABLE);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $lineId));
    }

    public function testAccountStepRejectsAConnectionWithoutASessionId(): void
    {
        $productId = self::seededProduct('ceramic-pot-18-cm-sand');
        $userId = $this->person('Cart step shopper');
        $lineId = self::accountLine($userId, $productId, 2);
        $this->startCartWorker();
        $owner = $this->personAgent($userId);
        $this->connectCartTab(self::FIRST_TAB, $userId, null);
        $this->subscribeCart($owner, self::FIRST_TAB);
        self::assertSame([$lineId], array_column($this->takeCartPage(self::FIRST_TAB)['lines'], 'lineId'));

        $this->dispatchCartStep($owner, 'account-no-session', $lineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_DOWN);
        $this->assertStepRefused('account-no-session', self::LINE_UNAVAILABLE);
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
        $this->assertStepRefused('foreign', self::LINE_UNAVAILABLE);
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
        $this->assertStepRefused('deleted', self::LINE_UNAVAILABLE);
        self::assertSame(1, self::storedQuantity(SessionProductCart::_table, $replacement));
        $this->dispatchCartStep($guest, 'fresh', $replacement, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        [, , $reply] = $this->takeStepOutcome('fresh');
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $replacement));

        Hilos::$db->sessions[$sessionId]->actions->bindUser($userId);
        $this->drainSignals();
        $this->dispatchCartStep($guest, 'stored-owner-changed', $replacement, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        $this->assertStepRefused('stored-owner-changed', self::LINE_UNAVAILABLE);

        $oldAction = self::cartStepSignal('before-handoff', $replacement, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        ExecutionContext::run(new ExecutionFrame(), static function () use ($userId): void {
            Hilos::$rt->connections[self::FIRST_TAB]->actions->bindUser($userId);
        });
        $this->deliverCartFrame($guest, $oldAction);
        $this->assertStepRefused('before-handoff', self::LINE_UNAVAILABLE);

        $owner = $this->personAgent($userId);
        $this->subscribeCart($owner, self::FIRST_TAB);
        $cart = $this->takeCartPage(self::FIRST_TAB);
        self::assertSame([$accountLineId], array_column($cart['lines'], 'lineId'));
        $this->dispatchCartStep($owner, 'after-handoff', $accountLineId, CartStepQuantityActionDTO::GUEST, CartLineRules::STEP_UP);
        $this->assertStepRefused('after-handoff', self::LINE_UNAVAILABLE);

        $afterDeparture = self::cartStepSignal('after-departure', $accountLineId, CartStepQuantityActionDTO::ACCOUNT, CartLineRules::STEP_UP);
        $this->deliverCartFrame($owner, self::webSocketFrame(
            SignalTypeConstants::PAGE_UNSUBSCRIBE, CartPage::PAGE, new WebSocketPageUnsubscribeSignalDTO(self::FIRST_TAB),
        ));
        $this->deliverCartFrame($owner, $afterDeparture);
        $this->assertStepRefused('after-departure', self::LINE_UNAVAILABLE);
        self::assertSame(2, self::storedQuantity(UserProductCart::_table, $accountLineId));
        self::assertSame(2, self::storedQuantity(SessionProductCart::_table, $replacement));
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
        $this->guestSessionId = $session->id ?? self::fail('The guest session was not persisted');
        $productId = self::seededProduct('pine-bark-5-l');

        foreach ([[3, true], [3, false], [2, true], [0, true], [0, false]] as [$quantity, $changed]) {
            [$reply, $frames] = $this->guestFixture($token, $productId, $quantity);
            self::assertTrue($reply->isOk());
            self::assertSame(['quantity' => $quantity], $reply->payload);
            $lines = $quantity === 0 ? [] : [['productId' => $productId, 'quantity' => $quantity]];
            $cart = ['userId' => null, 'lines' => $lines, 'voucherId' => null];
            self::assertSame($changed ? [[CartGroup::forSession($this->guestSessionId), $cart]] : [], $frames);
            $this->askGuestCart($this->guestSessionId);
            self::assertSame([[CartGroup::forSession($this->guestSessionId), $cart]], $this->cartStates());
        }
    }

    /** Refused exact quantities leave no partial line, and invalid sessions cannot change the cart. */
    public function testGuestCartFixtureRefusesUnavailableSessionsAndInvalidQuantities(): void
    {
        $token = SessionToken::mint();
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $this->guestSessionId = $session->id ?? self::fail('The guest session was not persisted');
        $stock = self::seededProduct('pine-bark-5-l');
        $unique = self::seededProduct('morning-meadow');

        $this->assertFixtureRefused($token, $stock, 999_999, 'Only 120 in stock');
        $this->assertFixtureRefused($token, $unique, 2, 'A unique piece has no quantity: it is one of a kind');
        $this->assertFixtureRefused($token, PHP_INT_MAX, 1, 'This product does not exist');
        $this->askGuestCart($this->guestSessionId);
        self::assertSame(
            [[CartGroup::forSession($this->guestSessionId), ['userId' => null, 'lines' => [], 'voucherId' => null]]],
            $this->cartStates(),
        );
        self::assertSame(0, (int)Database::sql(
            'SELECT COUNT(*) AS total FROM session_product_cart WHERE session_id = ?',
            [$this->guestSessionId],
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
        $this->guestSessionId = $session->id ?? self::fail('The guest session was not persisted');
        $productId = self::seededProduct('pine-bark-5-l');
        [$reply] = $this->guestFixture($token, $productId, 2);
        self::assertTrue($reply->isOk());

        // Update storage as another worker would, with its DB_SYNC still in flight.
        $expiresAt = TimeHelper::nowMs();
        Database::sqlRun('UPDATE `hilos_session` SET `expires_at` = ? WHERE `id` = ?', [$expiresAt, $this->guestSessionId]);
        self::assertTrue($session->expiresAt === null || $session->expiresAt > $expiresAt);

        $this->assertFixtureRefused($token, $productId, 1, 'This guest session is no longer available');
        self::assertSame(2, (int)Database::sql(
            'SELECT quantity FROM session_product_cart WHERE session_id = ? AND product_id = ?',
            [$this->guestSessionId, $productId],
        )->field('quantity'));
    }

    /** A guest's voucher-only cart is told on commit, on change and on request. */
    public function testGuestVoucherChangesAreToldToTheSessionGroup(): void
    {
        $sessionId = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint())->id
            ?? self::fail('The guest session was not persisted');
        $this->guestSessionId = $sessionId;
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

    /** @param callable(): void $change Voucher change to roll back */
    private function assertRolledBackWithoutCart(callable $change): void
    {
        $rollback = new RuntimeException('Roll back the voucher change');
        try {
            Database::transaction(DatabaseNames::PRIMARY, function () use ($change, $rollback): void {
                $change();
                self::assertSame([], $this->cartStates());

                throw $rollback;
            });
            self::fail('The voucher change committed');
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
        $this->guestSessionId = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint())->id
            ?? self::fail('The guest session was not persisted');

        return $this->guestSessionId;
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

    /** Releases the step cases' tabs and restores the browser context. */
    private function releaseCartWorker(): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(), static function (): void {
                foreach ([self::FIRST_TAB, self::OTHER_TAB] as $acceptKey) {
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
        ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey, $userId, $sessionId): void {
            Hilos::$rt->connections->actions->register($acceptKey, $userId, sessionId: $sessionId);
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
        $signal = self::cartStepSignal($requestId, $lineId, $kind, $step);
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
     * @param string $acceptKey Only tab owed a page response
     * @return array{lines: list<array<string, mixed>>, itemsTotal: string} Cart data from that response
     */
    private function takeCartPage(string $acceptKey): array
    {
        $pages = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frame = $signal->data;
            if ($frame instanceof WebSocketSignalData && $frame->data instanceof PageResponseSignalData) {
                $pages[] = $frame;
            }
        }
        self::assertCount(1, $pages);
        self::assertSame($acceptKey, $pages[0]->targetAcceptKey);
        $response = $pages[0]->data;
        self::assertSame(CartPage::PAGE, $response->pageKey);

        return $response->payload->data[CartPage::DATA_CART];
    }

    /**
     * @param string $requestId Request expected to receive the sole personal reply
     * @return array{list<DbSyncUpdatedSignalData>, list<array{string, array<string, mixed>, list<string>}>,
     *     PageActionSuccessSignalData|PageActionErrorSignalData} Persisted facts, group delivery and ack
     */
    private function takeStepOutcome(string $requestId): array
    {
        $facts = [];
        $states = [];
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frame = $signal->data;
            if ($frame instanceof DbSyncUpdatedSignalData) {
                $facts[] = $frame;
            }
            if (!$frame instanceof WebSocketSignalData) {
                continue;
            }
            self::assertNotInstanceOf(PageResponseSignalData::class, $frame->data);
            if ($frame->data instanceof PageActionSuccessSignalData || $frame->data instanceof PageActionErrorSignalData) {
                self::assertSame(self::FIRST_TAB, $frame->targetAcceptKey);
                $expectedSignal = $frame->data instanceof PageActionSuccessSignalData
                    ? SignalConstants::ACTION_SUCCESS
                    : SignalConstants::ACTION_ERROR;
                self::assertSame($expectedSignal, $signal->signalName->getName());
                self::assertSame(self::STEP_ACTION, $frame->data->action);
                self::assertSame($requestId, $frame->data->requestId);
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
     */
    private function assertStepRefused(string $requestId, string $reason): void
    {
        [$facts, $states, $reply] = $this->takeStepOutcome($requestId);
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

    /** @param DbSyncUpdatedSignalData ...$facts Committed changes as received from a peer worker */
    private function flushCartUpdates(DbSyncUpdatedSignalData ...$facts): void
    {
        ExecutionContext::run(new ExecutionFrame(), function () use ($facts): void {
            foreach ($facts as $fact) {
                $this->cartWorker->handleDaemonMessage(new WorkerDbSyncUpdatedMessageDTO($fact->withEmitter('cart-step-peer')));
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
     * Writes a line of a guest's cart past the guest carts library.
     *
     * @param int $sessionId Row of the session whose cart it is
     * @param int $productId Product of the line
     * @param int $quantity Units the line holds, one at least
     * @return int Persisted line PK
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
     * @return int Persisted line PK
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
