<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferClearedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferOfferSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferReceivedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferWakeSignalData;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\View\Item\Session;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\AccountOperation\Exception\AccountErasingException;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\DatabaseNames;
use Hilos\Utils\Helpers\TimeHelper;
use RuntimeException;

/** Session assignment and transfer delivery run under the agents' actual ownership claims. */
final class GuestCartTransferTest extends IntegrationTestCase
{
    private const int SOURCE_SESSION = 4_000_000_001;
    private const int SOURCE_EPOCH = 0;
    private const int NEXT_EPOCH = 1;
    private const int MAX_CART_QUANTITY = 9_007_199_254_740_991;
    private const int TRANSFERS_PER_PAGE = 25;
    private const string RECOVERY_RULE = 'guest_cart_sweep_recovery';
    private const string WAKE = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_WAKE;
    private const string OFFER = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_OFFER;
    private const string CLEARED = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_CLEARED;
    private const string RECEIVED = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_RECEIVED;

    /** @var list<int> People created by the case */
    private array $userIds = [];

    /** @var list<int> Sessions created by the case */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new EcommerceShopSignalRouter());
    }

    protected function tearDown(): void
    {
        try {
            foreach ([self::SOURCE_SESSION, ...$this->sessionIds] as $sessionId) {
                Database::sqlRun('DELETE FROM shop_guest_cart_transfer WHERE session_id = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM session_product_cart WHERE session_id = ?', [$sessionId]);
            }
            foreach ($this->sessionIds as $sessionId) {
                Database::sqlRun('DELETE FROM hilos_session WHERE id = ?', [$sessionId]);
            }
            foreach ($this->userIds as $userId) {
                Database::sqlRun('DELETE FROM user_product_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_account_deletion WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_user WHERE id = ?', [$userId]);
            }
            Hilos::$db->forgetLoadedDataForTest();
        } finally {
            Hilos::$sr = null;
            parent::tearDown();
        }
    }

    public function testBindAssignsOnlyTheDepartingGuestEpochAndLeavesRebindsAlone(): void
    {
        $first = $this->person('First cart recipient');
        $second = $this->person('Second cart recipient');
        $stock = self::product('pine-bark-5-l');
        $session = $this->guest();
        self::assertNotNull($session->id);
        Database::sqlRun(
            'INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, ?)',
            [$session->id, $stock, 3],
        );
        $impersonating = $this->guest();
        self::assertNotNull($impersonating->id);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 4, $impersonating->id);
        $empty = $this->guest();
        self::assertNotNull($empty->id);

        $this->underAgent($this->sessionsLibrary(), static function () use ($session, $impersonating, $empty, $first, $second): void {
            $session->actions->bindUser($first);
            $session->actions->bindUser($first);
            $session->actions->bindUser($second);
            $impersonating->actions->setImpersonator($first);
            $impersonating->actions->bindUser($second);
            $empty->actions->bindUser($first);
        });

        self::assertSame($second, $session->userId);
        self::assertSame(self::NEXT_EPOCH, $session->cartEpoch);
        $transferId = self::savedAssignment($session->id, self::SOURCE_EPOCH);
        self::assertNotNull($transferId);
        self::assertSame([$session->id, self::SOURCE_EPOCH, $first, false], Hilos::$db->shopGuestCartTransfers->storedTransfer($transferId));
        self::assertNull(self::savedAssignment($session->id, self::NEXT_EPOCH));
        self::assertSame(self::SOURCE_EPOCH, $impersonating->cartEpoch);
        self::assertNull(self::savedAssignment($impersonating->id, self::SOURCE_EPOCH));
        self::assertSame($first, $empty->userId);
        self::assertNull(self::savedAssignment($empty->id, self::SOURCE_EPOCH));
        self::assertNull(self::savedAssignment($empty->id, self::NEXT_EPOCH));
    }

    public function testRotationKeepsMintRefusalAndRollsBackWithTheOuterPrimary(): void
    {
        $userId = $this->person('Rotated cart recipient');
        $session = $this->guest();
        $occupied = $this->guest()->token;
        $oldToken = $session->token;
        $newToken = SessionToken::mint();
        self::assertNotNull($session->id);
        self::addSourceLine(self::SOURCE_EPOCH, self::product('pine-bark-5-l'), 3, $session->id);
        $stop = new RuntimeException('Rollback of the outer session transaction');
        $this->drainSignals();

        $this->underAgent($this->sessionsLibrary(), static function () use ($session, $userId, $occupied, $newToken, $oldToken, $stop): void {
            try {
                Database::transaction(DatabaseNames::PRIMARY, static function () use (
                    $session,
                    $userId,
                    $occupied,
                    $newToken,
                    $oldToken,
                    $stop,
                ): void {
                    try {
                        $session->actions->rotateTokenAndBindUser($occupied, $userId);
                        self::fail('The candidate belongs to a different session.');
                    } catch (DuplicateValueException) {
                        self::assertSame($oldToken, $session->token);
                        self::assertSame(self::SOURCE_EPOCH, $session->cartEpoch);
                        self::assertNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
                    }

                    $session->actions->rotateTokenAndBindUser($newToken, $userId);
                    self::assertSame($newToken, $session->token);
                    self::assertSame($userId, $session->userId);
                    self::assertSame(self::NEXT_EPOCH, $session->cartEpoch);
                    self::assertNotNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
                    self::assertNull(Hilos::$sr->getNextQueuedSignal());
                    throw $stop;
                });
                self::fail('The outer transaction should have rolled back.');
            } catch (RuntimeException $failure) {
                self::assertSame($stop, $failure);
            }

            self::assertSame($oldToken, $session->token);
            self::assertNull($session->userId);
            self::assertSame(self::SOURCE_EPOCH, $session->cartEpoch);
            self::assertSame(self::SOURCE_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($session->id, $oldToken));
            self::assertNull(Hilos::$db->sessions->findByToken($newToken));
            self::assertNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
            self::assertNull(Hilos::$sr->getNextQueuedSignal());

            $session->actions->rotateTokenAndBindUser($newToken, $userId);
            self::assertNull(Hilos::$db->sessions->findByToken($oldToken));
            self::assertSame(self::NEXT_EPOCH, Hilos::$db->sessions->findByToken($newToken)?->cartEpoch);
            self::assertNotNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
        });
    }

    public function testSessionStateWithoutTabsWakesItsRecipientAndRecoveryRepairsLostReplies(): void
    {
        $userId = $this->person('Recipient of the saved cart');
        $otherId = $this->person('Other account');
        $stock = self::product('pine-bark-5-l');
        $session = $this->guest();
        self::assertNotNull($session->id);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3, $session->id);
        $this->underAgent($this->sessionsLibrary(), static function () use ($session, $userId): void {
            $session->actions->bindUser($userId);
            $session->actions->unbindUser();
        });
        self::addSourceLine(self::NEXT_EPOCH, $stock, 2, $session->id);
        $transferId = self::savedAssignment($session->id, self::SOURCE_EPOCH);
        self::assertNotNull($transferId);

        $carts = $this->startGuestCarts();
        $this->drainSignals();
        $this->sendSessionStateWithoutTabs($session);
        $wake = $this->queuedTransferFrame(self::WAKE);
        self::assertSame(['sessionId' => $session->id], $wake->data->data->toArray());
        $this->refuseSourceFrame(
            $carts,
            self::WAKE,
            new GuestCartTransferWakeSignalData($session->id),
            $this->personAgent($userId)->getAgentSignalSource(),
        );
        $this->dispatchSourceFrame($carts, $wake);
        $offer = $this->queuedTransferFrame(self::OFFER);
        self::assertEquals(new GuestCartTransferOfferSignalData($transferId, $userId), $offer->data->data);

        $this->deliver($userId, self::OFFER, $offer->data->data);
        $received = $this->queuedTransferFrame(self::RECEIVED);
        self::assertInstanceOf(GuestCartTransferReceivedSignalData::class, $received->data->data);
        $this->refuseSourceFrame($carts, self::RECEIVED, $received->data->data, $this->personAgent($otherId)->getAgentSignalSource());
        self::assertSame([$stock => 3], self::accountLines($userId));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame([$stock => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));

        // Both receipts are lost after the account transaction commits.
        $this->drainSignals();
        $this->deliver($userId, self::OFFER, $offer->data->data);
        self::assertSame([[self::RECEIVED, $received->data->data->toArray()]], $this->transferFrames($userId));
        self::assertSame([$stock => 3], self::accountLines($userId));

        $this->runRecoveryTick($carts);
        $cleared = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals(new GuestCartTransferClearedSignalData($transferId, $userId), $cleared->data->data);
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$stock => 2], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));

        $this->drainSignals();
        $this->runRecoveryTick($carts);
        $replayed = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals($cleared->data->data, $replayed->data->data);
        $this->deliver($userId, self::CLEARED, $replayed->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
        self::assertSame([$stock => 2], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));

        $this->drainSignals();
        $this->deliver($userId, self::OFFER, $offer->data->data);
        self::assertSame([], $this->transferFrames($userId));
        self::assertSame([$stock => 3], self::accountLines($userId));
    }

    public function testRecoveryAfterLostWakesAndSessionSweepUsesBothSavedRecipients(): void
    {
        $first = $this->person('First recipient after sweep');
        $second = $this->person('Second recipient after sweep');
        $stock = self::product('pine-bark-5-l');
        $session = $this->guest();
        self::assertNotNull($session->id);
        $carts = $this->startGuestCarts();
        $library = $this->sessionsLibrary();
        self::addAccountLine($first, $stock, 2);
        self::addAccountLine($second, $stock, 5);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3, $session->id);

        $this->underAgent($library, static fn () => $session->actions->bindUser($first));
        $firstTransfer = self::savedAssignment($session->id, self::SOURCE_EPOCH);
        self::assertNotNull($firstTransfer);
        $this->sendSessionStateWithoutTabs($session);
        $this->queuedTransferFrame(self::WAKE);
        $this->underAgent($library, static fn () => $session->actions->unbindUser());
        self::assertSame(self::NEXT_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($session->id, $session->token));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));

        self::addSourceLine(self::NEXT_EPOCH, $stock, 4, $session->id);
        $this->underAgent($library, static fn () => $session->actions->bindUser($second));
        $secondTransfer = self::savedAssignment($session->id, self::NEXT_EPOCH);
        self::assertNotNull($secondTransfer);
        $this->sendSessionStateWithoutTabs($session);
        $this->queuedTransferFrame(self::WAKE);
        $this->underAgent($library, static fn () => $session->actions->expire());

        $live = $this->guest();
        self::assertNotNull($live->id);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 7, $live->id);
        $this->drainSignals();
        self::assertNotNull($library->armCronRuleForTest(CronRule::FRAMEWORK_NAME_PREFIX . 'sessions_expire'));
        $this->underAgent($library, $library->onTick(...));
        $swept = $this->queuedTransferFrame(HilosSignalConstants::HILOS_SESSIONS_SWEPT);
        self::assertEquals(new SessionsSweptSignalData([$session->token], [$session->id]), $swept->data->data);
        $this->dispatchSourceFrame($carts, $swept);

        self::assertNull(Hilos::$db->sessions->findByToken($session->token));
        self::assertSame([$session->id, self::SOURCE_EPOCH, $first, false], Hilos::$db->shopGuestCartTransfers->storedTransfer($firstTransfer));
        self::assertSame([$session->id, self::NEXT_EPOCH, $second, false], Hilos::$db->shopGuestCartTransfers->storedTransfer($secondTransfer));
        self::assertSame([$stock => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertSame([$stock => 2], self::accountLines($first));
        self::assertSame([$stock => 5], self::accountLines($second));

        $this->runRecoveryTick($carts);
        $offers = [$this->queuedTransferFrame(self::OFFER), $this->queuedTransferFrame(self::OFFER)];
        foreach ([[$firstTransfer, $first], [$secondTransfer, $second]] as $index => [$transferId, $userId]) {
            $offer = $offers[$index]->data->data;
            self::assertEquals(new GuestCartTransferOfferSignalData($transferId, $userId), $offer);
            $this->deliver($userId, self::OFFER, $offer);
            $this->dispatchSourceFrame($carts, $this->queuedTransferFrame(self::RECEIVED));
            $this->deliver($userId, self::CLEARED, $this->queuedTransferFrame(self::CLEARED)->data->data);
            self::assertNull(Hilos::$db->shopGuestCartTransfers->storedTransfer($transferId));
        }

        self::assertSame([$stock => 5], self::accountLines($first));
        self::assertSame([$stock => 9], self::accountLines($second));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertSame(self::SOURCE_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($live->id, $live->token));
        self::assertSame([$stock => 7], Hilos::$db->sessionProductCarts->storedCartQuantities($live->id, self::SOURCE_EPOCH));
    }

    public function testOffersOnlyTheSavedAddressForSmallAndLargeCarts(): void
    {
        $userId = $this->person('Recipient of two cart sizes');
        $products = self::firstProducts(26);
        self::addSourceLine(self::SOURCE_EPOCH, $products[0], 1);
        foreach ($products as $productId) {
            self::addSourceLine(self::NEXT_EPOCH, $productId, 1);
        }
        $small = $this->assign($userId);
        $large = $this->assign($userId, self::NEXT_EPOCH);
        $carts = $this->startGuestCarts();
        $this->drainSignals();

        $this->runRecoveryTick($carts);

        foreach ([$small, $large] as $transferId) {
            $offer = $this->queuedTransferFrame(self::OFFER)->data->data;
            self::assertSame(['transferId' => $transferId, 'userId' => $userId], $offer->toArray());
        }
    }

    public function testRecoveryReachesTheNextTransferPageAfterAnEmptyAssignmentAndOwnerRestart(): void
    {
        $userId = $this->person('Recipient beyond the recovery page');
        $stock = self::product('pine-bark-5-l');
        $waiting = $this->assign($userId);
        $firstPage = [$waiting];
        for ($epoch = 1; $epoch < self::TRANSFERS_PER_PAGE; $epoch++) {
            self::addSourceLine($epoch, $stock, 1);
            $firstPage[] = $this->assign($userId, $epoch);
        }
        self::addSourceLine(self::TRANSFERS_PER_PAGE, $stock, 1);
        $last = $this->assign($userId, self::TRANSFERS_PER_PAGE);

        $carts = $this->startGuestCarts();
        try {
            $this->drainSignals();
            $this->runRecoveryTick($carts);
            self::assertSame($firstPage, $this->queuedOfferIds());
        } finally {
            $this->underAgent($carts, $carts->onStop(...));
            TruthSourceRegistry::unregisterAgent($carts->getId());
        }

        $carts = $this->startGuestCarts();
        $this->runRecoveryTick($carts);
        self::assertSame($firstPage, $this->queuedOfferIds());
        $this->runRecoveryTick($carts);
        self::assertSame([$last], $this->queuedOfferIds());
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($waiting, $userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($last, $userId));
    }

    public function testRecoverySharesItsRowBudgetAcrossReceivedPairsAndReplaysClearance(): void
    {
        $first = $this->person('First received cart');
        $second = $this->person('Second received cart');
        $third = $this->person('Pending cart behind the received page');
        $pendingEpoch = self::NEXT_EPOCH + 1;
        $session = $this->guest();
        self::assertNotNull($session->id);
        $products = self::firstProducts(25);
        foreach (array_slice($products, 0, 10) as $productId) {
            self::addSourceLine(self::SOURCE_EPOCH, $productId, 1);
        }
        foreach ($products as $productId) {
            self::addSourceLine(self::NEXT_EPOCH, $productId, 1);
        }
        self::addSourceLine(self::SOURCE_EPOCH, $products[0], 4, $session->id);
        self::addAccountLine($first, $products[0], 5);
        self::addAccountLine($second, $products[0], 9);
        $firstTransfer = $this->assign($first);
        $secondTransfer = $this->assign($second, self::NEXT_EPOCH);
        self::addSourceLine($pendingEpoch, $products[0], 1);
        $pendingTransfer = $this->assign($third, $pendingEpoch);
        Database::sqlRun('UPDATE shop_guest_cart_transfer SET received = 1 WHERE id IN (?, ?)', [$firstTransfer, $secondTransfer]);
        $carts = $this->startGuestCarts();
        $this->drainSignals();

        $this->runRecoveryTick($carts);
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertCount(10, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        $firstClear = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals(new GuestCartTransferClearedSignalData($firstTransfer, $first), $firstClear->data->data);
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(self::CLEARED, $signal->signalName->getName());
        }
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($secondTransfer, $second));
        self::assertSame([$products[0] => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$products[0] => 5], self::accountLines($first));
        self::assertSame([$products[0] => 9], self::accountLines($second));
        $this->deliver($first, self::CLEARED, $firstClear->data->data);

        $this->runRecoveryTick($carts);
        self::assertCount(10, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertSame([$pendingTransfer], $this->queuedOfferIds());

        $this->runRecoveryTick($carts);
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        $secondClear = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals(new GuestCartTransferClearedSignalData($secondTransfer, $second), $secondClear->data->data);

        // Reenter after the last deletion has committed without an observed clearance.
        $this->runRecoveryTick($carts);
        $replayedClear = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals($secondClear->data->data, $replayedClear->data->data);
        $this->deliver($second, self::CLEARED, $replayedClear->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedTransfer($secondTransfer));
        self::assertSame([$products[0] => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$products[0] => 9], self::accountLines($second));
    }

    public function testAppliesOnceAndClearsOnlyWhenTheReceivedEpochIsEmpty(): void
    {
        $userId = $this->person('Transfer recipient');
        $stock = self::product('pine-bark-5-l');
        $unique = self::product('morning-meadow');
        self::addAccountLine($userId, $stock, self::MAX_CART_QUANTITY - 2);
        self::addAccountLine($userId, $unique, 1);
        $this->personAgent($userId);
        $stockLineId = (int)Database::sql('SELECT id FROM user_product_cart WHERE user_id = ? AND product_id = ?', [$userId, $stock])->field('id');
        $heldStock = Hilos::$db->userProductCarts[$stockLineId]->getObject();
        Database::sqlRun('UPDATE user_product_cart SET quantity = ? WHERE id = ?', [self::MAX_CART_QUANTITY - 1, $stockLineId]);
        self::assertSame(self::MAX_CART_QUANTITY - 2, $heldStock->quantity);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 2);
        self::addSourceLine(self::SOURCE_EPOCH, $unique, 1);
        self::addSourceLine(self::NEXT_EPOCH, $stock, 4);
        Hilos::$db->sessionProductCarts->objectCollection->preloadAll();
        Hilos::$db->shopGuestCartTransfers->objectCollection->preloadAll();
        Database::sqlRun(
            'UPDATE session_product_cart SET quantity = 1 WHERE session_id = ? AND cart_epoch = ? AND product_id = ?',
            [self::SOURCE_SESSION, self::SOURCE_EPOCH, $stock],
        );
        Database::sqlRun(
            'INSERT INTO shop_guest_cart_transfer (session_id, cart_epoch, user_id) VALUES (?, ?, ?)',
            [self::SOURCE_SESSION, self::SOURCE_EPOCH, $userId],
        );
        $transferId = self::savedAssignment(self::SOURCE_SESSION, self::SOURCE_EPOCH);
        self::assertNotNull($transferId);
        self::assertFalse(isset(Hilos::$db->shopGuestCartTransfers[$transferId]));
        $heldSource = Hilos::$db->sessionProductCarts->forCart(self::SOURCE_SESSION, self::SOURCE_EPOCH)
            ->filter(static fn (SessionProductCart $line): bool => $line->productId === $stock)->first();
        self::assertSame(2, $heldSource?->quantity);

        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $clear = new GuestCartTransferClearedSignalData($transferId, $userId);
        $this->drainSignals();

        $this->deliver($userId, self::OFFER, $offer);

        $receipt = ['transferId' => $transferId, 'userId' => $userId];
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId,
                'lines' => [['productId' => $stock, 'quantity' => self::MAX_CART_QUANTITY], ['productId' => $unique, 'quantity' => 1]],
                'voucherId' => null,
            ]],
            [self::RECEIVED, $receipt],
        ], $this->transferFrames($userId));
        self::assertSame([$stock => self::MAX_CART_QUANTITY, $unique => 1], self::accountLines($userId));
        self::assertSame($heldStock, Hilos::$db->userProductCarts[$stockLineId]->getObject());
        self::assertSame(self::MAX_CART_QUANTITY, $heldStock->quantity);
        self::assertLessThan(self::MAX_CART_QUANTITY, Hilos::$db->productWarehouseStocks->totalsOfProducts($stock)->of($stock)->unitCount);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));

        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([[self::RECEIVED, $receipt]], $this->transferFrames($userId));
        self::assertSame([$stock => self::MAX_CART_QUANTITY, $unique => 1], self::accountLines($userId));

        $this->deliver($userId, self::CLEARED, $clear);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::deleteSource();
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([[self::RECEIVED, $receipt]], $this->transferFrames($userId));
        $otherId = $this->person('Different clearance recipient');
        $this->deliver($otherId, self::CLEARED, new GuestCartTransferClearedSignalData($transferId, $otherId));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        $this->deliver($userId, self::CLEARED, $clear);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));

        $this->deliver($userId, self::CLEARED, $clear);
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([], $this->transferFrames($userId));
        self::assertSame([$stock => self::MAX_CART_QUANTITY, $unique => 1], self::accountLines($userId));
    }

    public function testRejectsTheWrongSenderAddressPayloadAndStoredSource(): void
    {
        $userId = $this->person('Assigned recipient');
        $otherId = $this->person('Different recipient');
        $stock = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3);
        self::addSourceLine(self::NEXT_EPOCH, $stock, 4);
        $transferId = $this->assign($userId);
        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $this->drainSignals();

        $indexedSender = new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE, (string)$userId);
        foreach ([[$userId, $indexedSender], [$otherId, null]] as [$recipient, $sender]) {
            try {
                $this->deliver($recipient, self::OFFER, $offer, $sender);
                self::fail('The agent accepted the wrong sender or recipient.');
            } catch (AgentException $refusal) {
                self::assertSame(AgentException::class, $refusal::class);
            }
        }

        try {
            $this->deliver($userId, self::OFFER, new GuestCartTransferClearedSignalData($transferId, $userId));
            self::fail('The offer handler accepted a different DTO.');
        } catch (InvalidAgentSignalPayloadException $refusal) {
            self::assertStringContainsString(self::OFFER, $refusal->getMessage());
        }

        $this->deliver($otherId, self::OFFER, new GuestCartTransferOfferSignalData($transferId, $otherId));
        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData(PHP_INT_MAX, $userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));

        self::deleteSource();
        $this->deliver($userId, self::OFFER, $offer);
        $this->deliver($userId, self::CLEARED, new GuestCartTransferClearedSignalData($transferId, $userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame([], $this->transferFrames($userId));
        self::assertSame([], self::accountLines($userId));
        self::assertSame([], self::accountLines($otherId));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
    }

    public function testSecondPageOverflowRollsBackTheFirstPageAndAllowsReplay(): void
    {
        $userId = $this->person('Transfer above the cart limit');
        $first = self::product('pine-bark-5-l');
        $products = self::firstProducts(26);
        $last = $products[25];
        $source = array_fill_keys($products, 1);
        $source[$first] = 5;
        self::addAccountLine($userId, $first, 4);
        self::addAccountLine($userId, $last, self::MAX_CART_QUANTITY);
        foreach (array_reverse($source, true) as $productId => $quantity) {
            self::addSourceLine(self::SOURCE_EPOCH, $productId, $quantity);
        }
        self::addSourceLine(self::NEXT_EPOCH, $last, 7);
        $transferId = $this->assign($userId);
        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $held = Hilos::$db->userProductCarts->whereColumnIs(UserProductCart::user_id, $userId)->first();
        self::assertNotNull($held);
        self::assertSame($first, $held->productId);
        self::assertSame(
            array_slice($source, 0, 25, true),
            Hilos::$db->sessionProductCarts->storedCartQuantityPage(self::SOURCE_SESSION, self::SOURCE_EPOCH, 0),
        );
        self::assertSame(
            [$last => 1],
            Hilos::$db->sessionProductCarts->storedCartQuantityPage(self::SOURCE_SESSION, self::SOURCE_EPOCH, $products[24]),
        );
        $this->drainSignals();

        try {
            $this->deliver($userId, self::OFFER, $offer);
            self::fail('The sum exceeded the safe integer limit.');
        } catch (AgentException $refusal) {
            self::assertInstanceOf(ValidationException::class, $refusal->getPrevious());
            self::assertSame('The combined cart quantity is too large.', $refusal->getPrevious()->getMessage());
        }

        self::assertSame(4, $held->quantity);
        self::assertSame([$first => 4, $last => self::MAX_CART_QUANTITY], self::accountLines($userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertEquals($source, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertSame([], $this->transferFrames($userId));

        Database::sqlRun('UPDATE user_product_cart SET quantity = ? WHERE user_id = ? AND product_id = ?', [
            self::MAX_CART_QUANTITY - 1, $userId, $last,
        ]);
        $this->deliver($userId, self::OFFER, $offer);
        $expected = array_replace($source, [$first => 9, $last => self::MAX_CART_QUANTITY]);
        $receipt = [self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]];
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame(9, $held->quantity);
        self::assertEquals($expected, self::accountLines($userId));
        $frames = $this->transferFrames($userId);
        self::assertSame([EcommerceShopSignalConstants::CART_STATE, self::RECEIVED], array_column($frames, 0));
        self::assertEquals($expected, array_column($frames[0][1]['lines'], 'quantity', 'productId'));
        self::assertSame($receipt, $frames[1]);

        self::deleteSource();
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([$receipt], $this->transferFrames($userId));
        self::assertEquals($expected, self::accountLines($userId));
        self::assertSame([$last => 7], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
    }

    public function testErasureRefusesFreshWritesAndStillAllowsAnExistingReceipt(): void
    {
        $userId = $this->person('Transfer and erasure');
        $stock = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3);
        self::addSourceLine(self::NEXT_EPOCH, $stock, 2);
        $transferId = $this->assign($userId);
        $pendingId = $this->assign($userId, self::NEXT_EPOCH);
        $committed = new GuestCartTransferOfferSignalData($transferId, $userId);
        $pending = new GuestCartTransferOfferSignalData($pendingId, $userId);
        $this->deliver($userId, self::OFFER, $committed);
        $this->drainSignals();

        $now = TimeHelper::nowMs();
        Database::sqlRun(
            'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at, started_at) VALUES (?, ?, ?, ?)',
            [$userId, $now, $now, $now],
        );

        try {
            $this->deliver($userId, self::OFFER, $pending);
            self::fail('A transfer wrote to an account whose erasure had begun.');
        } catch (AgentException $refusal) {
            self::assertInstanceOf(AccountErasingException::class, $refusal->getPrevious());
            self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($pendingId, $userId));
        }
        $this->deliver($userId, self::OFFER, $committed);

        self::assertSame([[self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]]], $this->transferFrames($userId));
        self::assertSame([$stock => 3], self::accountLines($userId));
    }

    private function startGuestCarts(): GuestCartsLibraryAgent
    {
        $carts = new GuestCartsLibraryAgent();
        $this->underAgent($carts, fn () => $this->startAgent($carts));

        return $carts;
    }

    private function runRecoveryTick(GuestCartsLibraryAgent $carts): void
    {
        self::assertNotNull($carts->armCronRuleForTest(self::RECOVERY_RULE));
        $this->underAgent($carts, $carts->onTick(...));
    }

    private function sendSessionStateWithoutTabs(Session $session): void
    {
        (new EcommerceShopAgent())->onSignalAgent(
            new AgentSignalData(new SessionStateSignalData($session->token, $session->id, $session->userId, [])),
            '',
            HilosSignalConstants::HILOS_SESSION_STATE,
        );
    }

    /** @return list<int> Saved transfer keys offered since the last drain */
    private function queuedOfferIds(): array
    {
        $ids = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === self::OFFER) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(GuestCartTransferOfferSignalData::class, $signal->data->data);
                $ids[] = $signal->data->data->transferId;
            }
        }

        return $ids;
    }

    private function queuedTransferFrame(string $name): SignalDTO
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                $frame = SignalDTO::fromJson($signal->toJson());
                self::assertInstanceOf(AgentSignalData::class, $frame->data);

                return $frame;
            }
        }

        self::fail('No ' . $name . ' frame was queued.');
    }

    private function dispatchSourceFrame(GuestCartsLibraryAgent $carts, SignalDTO $frame): void
    {
        $this->underAgent($carts, static fn () => $carts->onSignalAgent(
            $frame->data,
            SignalSource::describe($frame->signalSource),
            $frame->signalName->getName(),
        ));
    }

    private function refuseSourceFrame(
        GuestCartsLibraryAgent $carts,
        string $name,
        SignalDataInterface $payload,
        SignalSourceInterface $sender,
    ): void {
        try {
            $this->underAgent($carts, static fn () => $carts->onSignalAgent(new AgentSignalData($payload), SignalSource::describe($sender), $name));
            self::fail('The cart source accepted a transfer frame from a different sender.');
        } catch (AgentException $refusal) {
            self::assertStringContainsString('as the sender', $refusal->getMessage());
        }
    }

    /** Delivers an IPC roundtrip under the addressed agent's execution context. */
    private function deliver(int $recipient, string $name, SignalDataInterface $payload, ?SignalSource $sender = null): void
    {
        $owner = $this->personAgent($recipient);
        $frame = new SignalDTO(
            $sender ?? new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($name),
            new AgentSignalData($payload),
        );
        $delivered = SignalDTO::fromJson($frame->toJson());
        self::assertInstanceOf(AgentSignalData::class, $delivered->data);
        $this->underAgent($owner, static fn () => $owner->onSignalAgent(
            $delivered->data,
            SignalSource::describe($delivered->signalSource),
            $delivered->signalName->getName(),
        ));
    }

    /** @return list<array{string, array<string, mixed>}> The receipt and cart state announced by the recipient */
    private function transferFrames(int $userId): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($name === self::RECEIVED) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertEquals($this->personAgent($userId)->getAgentSignalSource(), $signal->signalSource);
                $frames[] = [$name, $signal->data->data->toArray()];
            } elseif ($name === EcommerceShopSignalConstants::CART_STATE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(CartGroup::forUser($userId), $signal->data->targetGroup);
                $frames[] = [$name, $signal->data->data->toArray()];
            }
        }

        return $frames;
    }

    private function person(string $name): int
    {
        $userId = Hilos::$db->users->actions->createWithName($name)->id ?? self::fail('The recipient was not persisted.');
        $this->userIds[] = $userId;

        return $userId;
    }

    private function guest(): Session
    {
        $session = $this->underAgent($this->sessionsLibrary(), static fn () => Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint()));
        self::assertNotNull($session->id);
        $this->sessionIds[] = $session->id;

        return $session;
    }

    private static function savedAssignment(int $sessionId, int $epoch): ?int
    {
        $id = Database::sql(
            'SELECT id FROM shop_guest_cart_transfer WHERE session_id = ? AND cart_epoch = ?',
            [$sessionId, $epoch],
        )->field('id');

        return $id === null ? null : (int)$id;
    }

    private function assign(int $userId, int $epoch = self::SOURCE_EPOCH): int
    {
        $this->underAgent($this->sessionsLibrary(), static function () use ($userId, $epoch): void {
            Hilos::$db->shopGuestCartTransfers->actions->create(self::SOURCE_SESSION, $epoch, $userId);
        });
        $id = self::savedAssignment(self::SOURCE_SESSION, $epoch);
        self::assertNotNull($id);

        return $id;
    }

    private static function product(string $slug): int
    {
        $id = Database::sql('SELECT id FROM product WHERE slug = ?', [$slug])->field('id');
        self::assertNotNull($id);

        return (int)$id;
    }

    /** @return list<int> IDs in catalog key order */
    private static function firstProducts(int $limit): array
    {
        $products = array_map('intval', array_column(Database::sql('SELECT id FROM product ORDER BY id LIMIT ?', [$limit])->rows(), 'id'));
        self::assertCount($limit, $products);

        return $products;
    }

    private static function addSourceLine(int $epoch, int $productId, int $quantity, int $sessionId = self::SOURCE_SESSION): void
    {
        Database::sqlRun(
            'INSERT INTO session_product_cart (session_id, cart_epoch, product_id, quantity) VALUES (?, ?, ?, ?)',
            [$sessionId, $epoch, $productId, $quantity],
        );
    }

    private static function addAccountLine(int $userId, int $productId, int $quantity): void
    {
        Database::sqlRun(
            'INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, ?)',
            [$userId, $productId, $quantity],
        );
    }

    private static function deleteSource(): void
    {
        Database::sqlRun('DELETE FROM session_product_cart WHERE session_id = ? AND cart_epoch = ?', [self::SOURCE_SESSION, self::SOURCE_EPOCH]);
    }

    /** @return array<int, int> Stored account quantities by product ID */
    private static function accountLines(int $userId): array
    {
        $lines = [];
        foreach (Database::sql('SELECT product_id, quantity FROM user_product_cart WHERE user_id = ? ORDER BY id', [$userId])->rows() as $row) {
            $lines[(int)$row['product_id']] = (int)$row['quantity'];
        }

        return $lines;
    }
}
