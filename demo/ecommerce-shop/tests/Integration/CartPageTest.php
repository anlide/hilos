<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\ProductFields;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\DTO\ProductStockTotalsCollection;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Pages\DTO\CartRemoveLineActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\PageHandoffIdentity;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
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
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Files\HilosFiles;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;
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
use ReflectionProperty;

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
        $accountVoucher = $this->voucher('CARTOWNER20', 20);
        self::guestVoucher($sessionId, $guestVoucher->id);
        self::accountVoucher($userId, $accountVoucher->id);
        self::connect(self::FIRST, null, $sessionId);
        $guest = new GuestCartsLibraryAgent();
        $account = new UserAgent((string)$userId);
        $this->subscribe($guest, self::FIRST);
        $cart = $this->cart(self::FIRST);
        self::assertSame([$guestProduct], array_column($cart['lines'], 'productId'));
        self::assertSame(['id' => $guestVoucher->id, 'code' => 'CARTGUEST10', 'active' => true, 'discount' => '1200'], $cart['voucher']);
        self::assertSame('10800', $cart['total']);

        self::bindFirstUser($userId);
        self::assertSame([], $this->flushCartFacts(self::productPriceFact($guestProduct, 8000), self::productPriceFact($accountProduct, 9000)));
        self::assertSame([], $this->takePageFrames());
        $this->handoff($guest, $account);
        $cart = $this->cart(self::FIRST);
        self::assertSame($userId, $cart['userId']);
        self::assertSame([$accountProduct], array_column($cart['lines'], 'productId'));
        self::assertSame(['id' => $accountVoucher->id, 'code' => 'CARTOWNER20', 'active' => true, 'discount' => '3600'], $cart['voucher']);
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
        self::assertSame(['id' => $guestVoucher->id, 'code' => 'CARTGUEST10', 'active' => false, 'discount' => '0'], $cart['voucher']);
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
            $this->subscribeTrackedPage($owner, $deliveryId);
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
        $voucher = $this->voucher('CARTLIVE20', 20);
        self::accountVoucher($userId, $voucher->id);
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
        self::assertSame(['id' => $voucher->id, 'code' => 'CARTLIVE20', 'active' => true, 'discount' => '3600'], $cart['voucher']);
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
        $bindingId = self::guestVoucher($sessionId, $voucher->id);
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST);

        $cart = $this->cart(self::FIRST);
        self::assertSame('9999999999999999', $cart['itemsTotal']);
        self::assertSame(['id' => $voucher->id, 'code' => 'CARTPAGE1', 'active' => true, 'discount' => '100000000000000'], $cart['voucher']);
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
        self::assertSame(['id' => $voucher->id, 'code' => 'CARTPAGE1', 'active' => false, 'discount' => '0'], $cart['voucher']);
        self::assertSame('9999999899999999', $cart['total']);
        self::assertSame($cart['itemsTotal'], $cart['total']);

        self::assertSame([], $this->flushCartFacts(self::guestVoucherRemoved($bindingId)));
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
        self::assertSame(['id' => $active->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '251'], $cart['voucher']);
        self::assertSame('751', $cart['total']);

        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        $cart = $this->cart(self::ACCOUNT);
        self::assertSame(['id' => $inactive->id, 'code' => 'CARTPAGEOFF10', 'active' => false, 'discount' => '0'], $cart['voucher']);
        self::assertSame('1002', $cart['total']);

        self::connect(self::SECOND, null, $otherSessionId);
        $this->subscribe($guest, self::SECOND);
        $cart = $this->cart(self::SECOND);
        self::assertSame([7], array_column($cart['lines'], 'quantity'));
        self::assertSame('5010', $cart['itemsTotal']);
        self::assertSame(['id' => $active->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '1253'], $cart['voucher']);
        self::assertSame('3757', $cart['total']);

        self::connect(self::EMPTY, null, $unrelatedSessionId);
        $this->subscribe($guest, self::EMPTY);
        $cart = $this->cart(self::EMPTY);
        self::assertSame([0], array_column($cart['lines'], 'availableQuantity'));
        self::assertSame('0', $cart['itemsTotal']);
        self::assertSame(['id' => $active->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '0'], $cart['voucher']);
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
            self::assertSame(['id' => $shared->id, 'code' => 'CARTPAGE25', 'active' => false, 'discount' => '0'], $cart['voucher']);
            self::assertSame('20000', $cart['itemsTotal']);
            self::assertSame('20000', $cart['total']);
        }

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $shared->actions->setActive(true))));
        foreach ($this->cartsByConnection([self::FIRST, self::ACCOUNT]) as $cart) {
            self::assertSame(['id' => $shared->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '5000'], $cart['voucher']);
            self::assertSame('15000', $cart['total']);
        }

        $facts = $this->voucherFacts(static fn () => $shared->actions->update('CARTPAGE30', 30, true));
        $facts[] = self::stockUnitsFact($stockId, 1);
        self::assertSame([], $this->flushCartFacts(...$facts));
        foreach ($this->cartsByConnection([self::FIRST, self::ACCOUNT]) as $cart) {
            self::assertSame([1], array_column($cart['lines'], 'availableQuantity'));
            self::assertSame('10000', $cart['itemsTotal']);
            self::assertSame(['id' => $shared->id, 'code' => 'CARTPAGE30', 'active' => true, 'discount' => '3000'], $cart['voucher']);
            self::assertSame('7000', $cart['total']);
        }
    }

    /** The catalog fact follows the saved slot even if the worker has not received its binding update. */
    public function testVoucherCatalogChangeMatchesTheStoredAccountBinding(): void
    {
        $userId = self::person();
        $otherUserId = self::person();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 5);
        $first = $this->voucher('CARTPAGE10', 10);
        $second = $this->voucher('CARTPAGE25', 25);
        self::accountLine($userId, $product, 2);
        self::accountLine($otherUserId, $product, 2);
        self::accountVoucher($userId, $first->id);
        self::accountVoucher($otherUserId, $first->id);

        self::assertSame($first->id, Hilos::$db->userVoucherCarts[$userId]->voucherId);
        Database::sql('UPDATE user_voucher_cart SET voucher_id = ? WHERE user_id = ?', [$second->id, $userId]);

        self::connect(self::ACCOUNT, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::ACCOUNT);
        $cart = $this->cart(self::ACCOUNT);
        self::assertSame(['id' => $second->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('18000', $cart['total']);
        self::assertSame($first->id, Hilos::$db->userVoucherCarts[$userId]->voucherId);

        self::connect(self::SECOND, $otherUserId, self::storedSession($otherUserId));
        $this->subscribe(new UserAgent((string) $otherUserId), self::SECOND);
        self::assertSame('21600', $this->cart(self::SECOND)['total']);

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $second->actions->setActive(false))));
        $cart = $this->cartsByConnection([self::ACCOUNT])[self::ACCOUNT];
        self::assertSame(['id' => $second->id, 'code' => 'CARTPAGE25', 'active' => false, 'discount' => '0'], $cart['voucher']);
        self::assertSame('24000', $cart['itemsTotal']);
        self::assertSame($cart['itemsTotal'], $cart['total']);
    }

    /** Binding facts answer the matching cart; a clear answers every cart of that kind. */
    public function testVoucherBindingFactsAreAddressedToTheirOwner(): void
    {
        $otherSessionId = self::storedSession();
        $sessionId = self::storedSession();
        $cartEpoch = 1;
        Database::sql('UPDATE hilos_session SET cart_epoch = ? WHERE id IN (?, ?)', [$cartEpoch, $sessionId, $otherSessionId]);
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 5);
        $userId = self::person();
        $first = $this->voucher('CARTPAGE25', 25);
        $second = $this->voucher('CARTPAGE50', 50);
        $departed = $this->voucher('CARTPAGEDEPARTED', 15);
        self::guestLine($sessionId, $product, 1, $cartEpoch);
        self::guestLine($otherSessionId, $product, 1, $cartEpoch);
        $otherStaleId = self::guestVoucher($sessionId, $first->id);
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

        $bindingId = max($sessionId, $otherSessionId, $otherStaleId) + 1;
        Database::sql(
            'INSERT INTO session_voucher_cart (id, session_id, cart_epoch, voucher_id) VALUES (?, ?, ?, ?)',
            [$bindingId, $otherSessionId, $cartEpoch, $first->id],
        );
        self::assertSame([], $this->flushCartFacts(self::guestVoucherCreated($bindingId)));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertSame(['id' => $first->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '3000'], $cart['voucher']);
        self::assertSame('9000', $cart['total']);

        $staleId = self::guestVoucher($otherSessionId, $departed->id);
        self::assertSame([], $this->flushCartFacts(self::guestVoucherCreated($staleId)));
        self::assertSame([], $this->takePageFrames());
        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $departed->actions->setActive(false))));
        self::assertSame([], $this->takePageFrames());

        Database::sql('UPDATE session_voucher_cart SET voucher_id = ? WHERE id = ?', [$second->id, $staleId]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::sessionVoucherCarts,
            (string)$staleId,
            [SessionVoucherCart::voucher_id => $second->id],
            previous: [SessionVoucherCart::voucher_id => $departed->id],
        ))));
        self::assertSame([], $this->takePageFrames());
        self::assertSame([], $this->flushCartFacts(self::guestVoucherRemoved($staleId)));
        self::assertSame([], $this->takePageFrames());

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
        self::assertSame(['id' => $second->id, 'code' => 'CARTPAGE50', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('6000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $first->actions->setActive(false))));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertSame(['id' => $first->id, 'code' => 'CARTPAGE25', 'active' => false, 'discount' => '0'], $cart['voucher']);

        Database::sql('UPDATE session_voucher_cart SET voucher_id = ? WHERE id = ?', [$second->id, $bindingId]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::sessionVoucherCarts,
            (string)$bindingId,
            [SessionVoucherCart::voucher_id => $second->id],
            previous: [SessionVoucherCart::voucher_id => $first->id],
        ))));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertSame(['id' => $second->id, 'code' => 'CARTPAGE50', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('6000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(self::guestVoucherRemoved($bindingId)));
        $cart = $this->cartsByConnection([self::SECOND])[self::SECOND];
        self::assertNull($cart['voucher']);
        self::assertSame('12000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(self::accountVoucherRemoved($userId)));
        $cart = $this->cartsByConnection([self::ACCOUNT])[self::ACCOUNT];
        self::assertNull($cart['voucher']);
        self::assertSame('12000', $cart['total']);

        self::guestVoucher($sessionId, $second->id, $cartEpoch);
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
            self::assertSame(['id' => $voucher->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '3000'], $cart['voucher']);
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
        $this->subscribeTrackedPage(new GuestCartsLibraryAgent(), self::FIRST . ':1');
        $initial = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageResponseSignalData::class, $initial);
        self::assertNotNull($initial->pageSubscriptionProof);
        self::assertSame('18000', $initial->payload->data[CartPage::DATA_CART]['total']);

        self::assertSame([], $this->flushWithStockReadFailure(false, ...$this->voucherFacts(
            static fn () => $voucher->actions->setActive(false),
        )));
        $error = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $error->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $voucher->actions->setActive(true))));
        $recovered = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageResponseSignalData::class, $recovered);
        self::assertSame($initial->pageSubscriptionProof, $recovered->pageSubscriptionProof);
        $cart = $recovered->payload->data[CartPage::DATA_CART];
        self::assertSame(['id' => $voucher->id, 'code' => 'CARTPAGE25', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('18000', $cart['total']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::FIRST));
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
     * @param int $sessionId Guest session whose cart holds the voucher
     * @param int $voucherId Selected catalog voucher
     * @param int $cartEpoch Number of the guest cart
     * @return int Inserted binding ID
     */
    private static function guestVoucher(int $sessionId, int $voucherId, int $cartEpoch = 0): int
    {
        Database::sql(
            'INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) VALUES (?, ?, ?)',
            [$sessionId, $cartEpoch, $voucherId],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $bindingId Physical key of the new guest voucher
     * @return WorkerDbSyncCreatedMessageDTO Creation carrying the saved cart coordinate
     */
    private static function guestVoucherCreated(int $bindingId): WorkerDbSyncCreatedMessageDTO
    {
        $binding = SessionVoucherCart::getById($bindingId) ?? self::fail('The guest voucher binding was not persisted');

        return new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
            EcommerceShopDbContext::sessionVoucherCarts,
            (string)$bindingId,
            $binding->toArray(),
        ));
    }

    /**
     * @param int $bindingId Physical key of the guest voucher taken off
     * @return WorkerDbSyncDeletedMessageDTO Removal as the cart owner announces it
     */
    private static function guestVoucherRemoved(int $bindingId): WorkerDbSyncDeletedMessageDTO
    {
        $binding = SessionVoucherCart::getById($bindingId) ?? self::fail('The guest voucher binding was not persisted');
        Database::sql('DELETE FROM session_voucher_cart WHERE id = ?', [$bindingId]);

        return new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(
            EcommerceShopDbContext::sessionVoucherCarts,
            (string)$bindingId,
            $binding->toArray(),
        ));
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

    /** @param string $acceptKey Connection that must receive a refusal without a cart */
    private function assertRefusal(string $acceptKey): void
    {
        $reply = $this->pageReply($acceptKey);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $reply);
        self::assertSame(CartPage::PAGE, $reply->page);
        self::assertSame(HttpConstants::HTTP_CONFLICT, $reply->httpCode);
    }

}
