<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferClearedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferOfferSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferReceivedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferWakeSignalData;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\View\Item\Session;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart;
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
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\DatabaseNames;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
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
                Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ?', [$sessionId]);
            }
            foreach ($this->sessionIds as $sessionId) {
                Database::sqlRun('DELETE FROM hilos_session WHERE id = ?', [$sessionId]);
            }
            foreach ($this->userIds as $userId) {
                Database::sqlRun('DELETE FROM user_product_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM user_voucher_cart WHERE user_id = ?', [$userId]);
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
        $firstVoucher = self::voucherId('SPRING10');
        $secondVoucher = self::voucherId('WEDDING15');
        $session = $this->guest();
        self::assertNotNull($session->id);
        $carts = $this->startGuestCarts();
        $library = $this->sessionsLibrary();
        self::addAccountLine($first, $stock, 2);
        self::addAccountLine($second, $stock, 5);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3, $session->id);
        $this->underAgent($carts, static fn () => Hilos::$db->sessionVoucherCarts->actions->create(
            $session->id, self::SOURCE_EPOCH, $firstVoucher,
        ));

        $this->underAgent($library, static fn () => $session->actions->bindUser($first));
        $firstTransfer = self::savedAssignment($session->id, self::SOURCE_EPOCH);
        self::assertNotNull($firstTransfer);
        $this->sendSessionStateWithoutTabs($session);
        $this->queuedTransferFrame(self::WAKE);
        $this->underAgent($library, static fn () => $session->actions->unbindUser());
        self::assertSame(self::NEXT_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($session->id, $session->token));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));

        self::addSourceLine(self::NEXT_EPOCH, $stock, 4, $session->id);
        $this->underAgent($carts, static fn () => Hilos::$db->sessionVoucherCarts->actions->create(
            $session->id, self::NEXT_EPOCH, $secondVoucher,
        ));
        $this->underAgent($library, static fn () => $session->actions->bindUser($second));
        $secondTransfer = self::savedAssignment($session->id, self::NEXT_EPOCH);
        self::assertNotNull($secondTransfer);
        $this->sendSessionStateWithoutTabs($session);
        $this->queuedTransferFrame(self::WAKE);
        $this->underAgent($library, static fn () => $session->actions->expire());

        $live = $this->guest();
        self::assertNotNull($live->id);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 7, $live->id);
        $this->underAgent($carts, static fn () => Hilos::$db->sessionVoucherCarts->actions->create(
            $live->id, self::SOURCE_EPOCH, $secondVoucher,
        ));
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
        self::assertSame($firstVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::SOURCE_EPOCH));
        self::assertSame($secondVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));
        self::assertSame([$stock => 2], self::accountLines($first));
        self::assertSame([$stock => 5], self::accountLines($second));

        $this->runRecoveryTick($carts);
        $firstOffer = $this->queuedTransferFrame(self::OFFER);
        $secondOffer = $this->queuedTransferFrame(self::OFFER);
        self::assertEquals(new GuestCartTransferOfferSignalData($firstTransfer, $first), $firstOffer->data->data);
        self::assertEquals(new GuestCartTransferOfferSignalData($secondTransfer, $second), $secondOffer->data->data);
        $this->deliver($first, self::OFFER, $firstOffer->data->data);
        $this->queuedTransferFrame(self::RECEIVED);
        $this->dispatchSourceFrame($carts, $swept);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($firstTransfer, $first));
        self::assertSame([$stock => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame($firstVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::SOURCE_EPOCH));

        $this->deliver($first, self::OFFER, $firstOffer->data->data);
        $this->dispatchSourceFrame($carts, $this->queuedTransferFrame(self::RECEIVED));
        $this->deliver($first, self::CLEARED, $this->queuedTransferFrame(self::CLEARED)->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedTransfer($firstTransfer));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::SOURCE_EPOCH));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($secondTransfer, $second));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertSame($secondVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));

        $this->deliver($second, self::OFFER, $secondOffer->data->data);
        $this->dispatchSourceFrame($carts, $this->queuedTransferFrame(self::RECEIVED));
        $this->deliver($second, self::CLEARED, $this->queuedTransferFrame(self::CLEARED)->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedTransfer($secondTransfer));

        self::assertSame([$stock => 5], self::accountLines($first));
        self::assertSame([$stock => 9], self::accountLines($second));
        self::assertSame($firstVoucher, self::accountVoucherId($first));
        self::assertSame($secondVoucher, self::accountVoucherId($second));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));
        self::assertSame(self::SOURCE_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($live->id, $live->token));
        self::assertSame([$stock => 7], Hilos::$db->sessionProductCarts->storedCartQuantities($live->id, self::SOURCE_EPOCH));
        self::assertSame($secondVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($live->id, self::SOURCE_EPOCH));
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
            $offer = $this->queuedTransferFrame(self::OFFER);
            self::assertSame(['transferId' => $transferId, 'userId' => $userId], $offer->data->data->toArray());
            $recipient = $this->personAgent($userId);
            self::assertEquals([new AgentDestination($recipient->getType(), $recipient->getIndex())], Hilos::$sr->getDestinations($offer));
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
        $voucherId = (int)Database::sql('SELECT id FROM voucher WHERE code = ?', ['SPRING10'])->field('id');
        Database::sqlRun(
            'INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) VALUES (?, ?, ?), (?, ?, ?)',
            [self::SOURCE_SESSION, self::SOURCE_EPOCH, $voucherId, self::SOURCE_SESSION, self::NEXT_EPOCH, $voucherId],
        );
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
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertCount(11, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertSame($voucherId, Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::NEXT_EPOCH));
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
        self::assertCount(11, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertSame([$pendingTransfer], $this->queuedOfferIds());

        $this->runRecoveryTick($carts);
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::NEXT_EPOCH));
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

    /** Empty and product-only receipts clear even while unrelated voucher objects await DB sync. */
    public function testRecoveryClearsVoucherlessReceiptsWithALargeHeldVoucherMirror(): void
    {
        $unrelated = $this->guest()->id;
        $voucherId = self::voucherId('SPRING10');
        $product = self::product('pine-bark-5-l');
        Database::sqlRun(
            'INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) SELECT ?, seq, ? FROM seq_1_to_512',
            [$unrelated, $voucherId],
        );
        $vouchers = Hilos::$db->sessionVoucherCarts;
        $heldCount = 0;
        foreach ($vouchers->whereColumnIs(SessionVoucherCart::sessionId, $unrelated) as $binding) {
            self::assertSame($voucherId, $binding->voucherId);
            ++$heldCount;
        }
        self::assertSame(512, $heldCount);
        Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ?', [$unrelated]);

        $userId = $this->person('Recipient of voucherless carts');
        $transfers = [];
        foreach ([0, 1, 2, 3] as $epoch) {
            $transfers[] = $this->assign($userId, $epoch);
        }
        foreach ([1, 3] as $epoch) {
            self::addSourceLine($epoch, $product, 1);
        }
        Database::sqlRun('UPDATE shop_guest_cart_transfer SET received = TRUE WHERE session_id = ?', [self::SOURCE_SESSION]);
        $carts = $this->startGuestCarts();
        $this->drainSignals();

        $this->runRecoveryTick($carts);
        foreach ($transfers as $epoch => $transferId) {
            self::assertEquals(
                new GuestCartTransferClearedSignalData($transferId, $userId),
                $this->queuedTransferFrame(self::CLEARED)->data->data,
            );
            self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, $epoch));
        }
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
        $voucherId = self::voucherId('SPRING10');
        self::addSourceVoucher(self::SOURCE_EPOCH, $voucherId);
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
                'voucherId' => $voucherId,
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
        $this->deliver($userId, self::CLEARED, $clear);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ? AND cart_epoch = ?', [self::SOURCE_SESSION, self::SOURCE_EPOCH]);
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
        self::assertSame($voucherId, self::accountVoucherId($userId));
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

    /** A receipt write failure rolls back the already written cart and restores the same held objects. */
    public function testReceiptFailureRollsBackProductsVoucherAndReceiptBeforeReplay(): void
    {
        $userId = $this->person('Recipient with an interrupted receipt');
        $product = self::product('pine-bark-5-l');
        $previous = self::voucherId('SPRING10');
        $incoming = self::voucherId('WEDDING15');
        $owner = $this->personAgent($userId);
        $this->underAgent($owner, static fn () => $owner->applyVoucher('WEDDING15'));
        self::addAccountLine($userId, $product, 2);
        self::addSourceLine(self::SOURCE_EPOCH, $product, 3);
        self::addSourceVoucher(self::SOURCE_EPOCH, $incoming);
        $transferId = $this->assign($userId);
        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $heldLine = Hilos::$db->userProductCarts->whereColumnIs(UserProductCart::user_id, $userId)->first();
        self::assertNotNull($heldLine);
        $heldVoucher = Hilos::$db->userVoucherCarts[$userId]->getObject();
        $heldTransfer = Hilos::$db->shopGuestCartTransfers[$transferId]->getObject();
        Database::sqlRun('UPDATE user_voucher_cart SET voucher_id = ? WHERE user_id = ?', [$previous, $userId]);
        self::assertSame($incoming, $heldVoucher->voucherId);
        $this->drainSignals();

        $probe = new class ((string)$transferId) implements SourceMirrorSubscriberInterface {
            public ?Closure $beforeRollback = null;

            /** @param string $transferKey Row whose receipt is interrupted */
            public function __construct(private readonly string $transferKey)
            {
            }

            /**
             * @param SourceChange $change Mutation reaching the mirror inside PRIMARY
             * @param SourceChangeProvenance $provenance Origin of the mutation
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === EcommerceShopDbContext::shopGuestCartTransfers && $change->sourceId === $this->transferKey
                    && $change->mutationType === TableMutationType::Update && $this->beforeRollback !== null
                ) {
                    $check = $this->beforeRollback;
                    $this->beforeRollback = null;
                    $check();
                    throw new RuntimeException('Injected guest cart receipt failure');
                }
            }
        };
        $probe->beforeRollback = static function () use ($userId, $product, $incoming, $transferId): void {
            self::assertSame([$product => 5], self::accountLines($userId));
            self::assertSame($incoming, self::accountVoucherId($userId));
            self::assertTrue(ShopGuestCartTransfer::getById($transferId)?->received);
        };
        SourceChangeBus::subscribe($probe);
        try {
            $this->deliver($userId, self::OFFER, $offer);
            self::fail('The receipt failure did not reach the transfer handler.');
        } catch (AgentException $failure) {
            self::assertInstanceOf(SourceChangeSubscriberException::class, $failure->getPrevious());
            self::assertSame('Injected guest cart receipt failure', $failure->getPrevious()->getPrevious()?->getMessage());
        } finally {
            $probe->beforeRollback = null;
        }

        self::assertSame([$product => 2], self::accountLines($userId));
        self::assertSame($previous, self::accountVoucherId($userId));
        self::assertFalse(ShopGuestCartTransfer::getById($transferId)?->received);
        self::assertSame(2, $heldLine->quantity);
        self::assertSame($heldVoucher, Hilos::$db->userVoucherCarts[$userId]->getObject());
        self::assertSame($incoming, $heldVoucher->voucherId);
        self::assertSame($heldTransfer, Hilos::$db->shopGuestCartTransfers[$transferId]->getObject());
        self::assertFalse($heldTransfer->received);
        self::assertSame([$product => 3], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertSame($incoming, Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertSame([], $this->transferFrames($userId, expectNoVoucherWrite: true));

        $receipt = [self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]];
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 5]], 'voucherId' => $incoming,
            ]],
            $receipt,
        ], $this->transferFrames($userId));
        self::assertSame(5, $heldLine->quantity);
        self::assertSame($incoming, $heldVoucher->voucherId);
        self::assertSame($incoming, self::accountVoucherId($userId));
        self::assertTrue($heldTransfer->received);

        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([$receipt], $this->transferFrames($userId, expectNoVoucherWrite: true));
        self::assertSame([$product => 5], self::accountLines($userId));
        self::assertSame($incoming, self::accountVoucherId($userId));
    }

    /** Manual confirmation, recreation and removal use the saved slot even before a mirror update. */
    public function testManualVoucherWritesReconcileTheSavedSlot(): void
    {
        $userId = $this->person('Account with a stale voucher slot');
        $owner = $this->personAgent($userId);
        $selected = self::voucherId('SPRING10');
        $other = self::voucherId('WEDDING15');
        $apply = fn () => $this->underAgent($owner, static fn () => $owner->applyVoucher('SPRING10'));
        $apply();
        $this->drainSignals();

        $held = Hilos::$db->userVoucherCarts[$userId]->getObject();
        Database::sqlRun('UPDATE user_voucher_cart SET voucher_id = ? WHERE user_id = ?', [$other, $userId]);
        self::assertSame($selected, $held->voucherId);
        $apply();
        $selectedFrame = [EcommerceShopSignalConstants::CART_STATE, ['userId' => $userId, 'lines' => [], 'voucherId' => $selected]];
        self::assertSame([$selectedFrame], $this->transferFrames($userId));
        self::assertSame($selected, self::accountVoucherId($userId));

        $apply();
        self::assertSame([], $this->transferFrames($userId, expectNoVoucherWrite: true));

        Database::sqlRun('DELETE FROM user_voucher_cart WHERE user_id = ?', [$userId]);
        self::assertSame($held, Hilos::$db->userVoucherCarts[$userId]->getObject());
        $stop = new RuntimeException('Roll back the recreated voucher slot');
        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($apply, $selected, $userId, $stop): void {
                $apply();
                self::assertSame($selected, self::accountVoucherId($userId));
                throw $stop;
            });
        } catch (RuntimeException $failure) {
            self::assertSame($stop, $failure);
        }
        self::assertSame([], $this->transferFrames($userId, expectNoVoucherWrite: true));
        self::assertNull(self::accountVoucherId($userId));
        self::assertSame($held, Hilos::$db->userVoucherCarts[$userId]->getObject());

        $apply();
        self::assertSame([$selectedFrame], $this->transferFrames($userId));
        self::assertSame($selected, self::accountVoucherId($userId));
        self::assertNotSame($held, Hilos::$db->userVoucherCarts[$userId]->getObject());

        $this->underAgent($owner, $owner->removeVoucher(...));
        $this->drainSignals();
        self::assertFalse(isset(Hilos::$db->userVoucherCarts[$userId]));
        Database::sqlRun('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $other]);
        $this->underAgent($owner, $owner->removeVoucher(...));
        self::assertNull(self::accountVoucherId($userId));
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, ['userId' => $userId, 'lines' => [], 'voucherId' => null]],
        ], $this->transferFrames($userId));
    }

    /** @return iterable<string, array{?string, ?string, bool, ?string}> Original slot, applied code, refusal, final code */
    public static function manualVoucherChoices(): iterable
    {
        yield 'apply a different code' => ['WEDDING15', 'SPRING10', false, 'SPRING10'];
        yield 'confirm the same code' => ['SPRING10', 'SPRING10', false, 'SPRING10'];
        yield 'remove the selected code' => ['SPRING10', null, false, null];
        yield 'remove from an empty slot' => [null, null, false, null];
        yield 'apply an inactive code' => ['SPRING10', 'WELCOME5', true, 'WEDDING15'];
    }

    /**
     * @param ?string $previousCode Code selected before the guest assignment
     * @param ?string $apply Code to apply manually, or null for Remove
     * @param bool $refused Whether the manual choice fails
     * @param ?string $finalCode Code after receipt of the guest cart
     */
    #[DataProvider('manualVoucherChoices')]
    public function testManualVoucherChoicesSetPrecedenceForPendingOffers(
        ?string $previousCode,
        ?string $apply,
        bool $refused,
        ?string $finalCode,
    ): void {
        $userId = $this->person('Recipient who chooses a voucher');
        $owner = $this->personAgent($userId);
        if ($previousCode !== null) {
            $this->underAgent($owner, static fn () => $owner->applyVoucher($previousCode));
        }
        $product = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 1);
        self::addSourceVoucher(self::SOURCE_EPOCH, self::voucherId('WEDDING15'));
        $transferId = $this->assign($userId);
        self::assertFalse(ShopGuestCartTransfer::getById($transferId)?->voucher_superseded);
        $this->drainSignals();

        try {
            $this->underAgent($owner, $apply === null ? $owner->removeVoucher(...) : static fn () => $owner->applyVoucher($apply));
            self::assertFalse($refused);
        } catch (ValidationException $failure) {
            self::assertTrue($refused);
            self::assertSame('This voucher is inactive', $failure->getMessage());
        }
        self::assertSame(!$refused, ShopGuestCartTransfer::getById($transferId)?->voucher_superseded);
        $manuallyChosen = $refused ? $previousCode : $apply;
        $manualVoucher = $manuallyChosen === null ? null : self::voucherId($manuallyChosen);
        self::assertSame($manualVoucher, self::accountVoucherId($userId));
        $unchanged = $manuallyChosen === $previousCode;
        self::assertSame($unchanged ? [] : [
            [EcommerceShopSignalConstants::CART_STATE, ['userId' => $userId, 'lines' => [], 'voucherId' => $manualVoucher]],
        ], $this->transferFrames($userId, expectNoVoucherWrite: $unchanged));

        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData($transferId, $userId));
        $finalVoucher = $finalCode === null ? null : self::voucherId($finalCode);
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 1]], 'voucherId' => $finalVoucher,
            ]],
            [self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]],
        ], $this->transferFrames($userId));
        self::assertSame($finalVoucher, self::accountVoucherId($userId));
    }

    /** @return iterable<string, array{?string, bool}> Later raw voucher choice and whether the receipt is already saved */
    public static function laterGuestVoucherOffers(): iterable
    {
        yield 'fresh offer with a different ID' => ['WELCOME5', false];
        yield 'fresh offer with the selected ID' => ['SPRING10', false];
        yield 'fresh offer without a voucher' => [null, false];
        yield 'replayed receipt' => ['WELCOME5', true];
    }

    /**
     * @param ?string $laterCode Voucher on the later source, or null for products only
     * @param bool $received Whether this offer is a replay of a saved receipt
     */
    #[DataProvider('laterGuestVoucherOffers')]
    public function testLaterOffersOnlySupersedeEarlierVouchersOnAFreshVoucherChoice(?string $laterCode, bool $received): void
    {
        $userId = $this->person('Recipient of out-of-order vouchers');
        $product = self::product('pine-bark-5-l');
        $owner = $this->personAgent($userId);
        $this->underAgent($owner, static fn () => $owner->applyVoucher('SPRING10'));
        $earlierVoucher = self::voucherId('WEDDING15');
        $currentVoucher = self::voucherId('SPRING10');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 2);
        self::addSourceVoucher(self::SOURCE_EPOCH, $earlierVoucher);
        $earlier = $this->assign($userId);
        self::addSourceLine(self::NEXT_EPOCH, $product, 3);
        $laterVoucher = $laterCode === null ? null : self::voucherId($laterCode);
        if ($laterVoucher !== null) {
            self::addSourceVoucher(self::NEXT_EPOCH, $laterVoucher);
        }
        $later = $this->assign($userId, self::NEXT_EPOCH);
        if ($received) {
            // Storage already has the later cart and receipt while this worker still has its pending mirror.
            self::addAccountLine($userId, $product, 3);
            Database::sqlRun('UPDATE shop_guest_cart_transfer SET received = TRUE WHERE id = ?', [$later]);
        }
        $this->drainSignals();

        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData($later, $userId));
        $laterReceipt = [self::RECEIVED, ['transferId' => $later, 'userId' => $userId]];
        $superseded = $laterVoucher !== null && !$received;
        $chosenByLater = $superseded ? $laterVoucher : $currentVoucher;
        $laterCart = [EcommerceShopSignalConstants::CART_STATE, [
            'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 3]], 'voucherId' => $chosenByLater,
        ]];
        self::assertSame(
            $received ? [$laterReceipt] : [$laterCart, $laterReceipt],
            $this->transferFrames($userId, expectNoVoucherWrite: $chosenByLater === $currentVoucher),
        );
        self::assertSame($superseded, ShopGuestCartTransfer::getById($earlier)?->voucher_superseded);
        self::assertTrue(ShopGuestCartTransfer::getById($later)?->received);
        self::assertSame($chosenByLater, self::accountVoucherId($userId));

        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData($earlier, $userId));
        $finalVoucher = $superseded ? $chosenByLater : $earlierVoucher;
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 5]], 'voucherId' => $finalVoucher,
            ]],
            [self::RECEIVED, ['transferId' => $earlier, 'userId' => $userId]],
        ], $this->transferFrames($userId, expectNoVoucherWrite: $superseded));
        self::assertSame([$product => 5], self::accountLines($userId));
        self::assertSame($finalVoucher, self::accountVoucherId($userId));
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

    /**
     * @param int $userId Recipient whose frames are expected
     * @param bool $expectNoVoucherWrite Whether emitting a voucher slot fact fails the test
     * @return list<array{string, array<string, mixed>}> The receipt and cart state announced by the recipient
     */
    private function transferFrames(int $userId, bool $expectNoVoucherWrite = false): array
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
            } elseif ($expectNoVoucherWrite && $signal->data instanceof DbSyncSignalDataInterface) {
                self::assertNotSame(EcommerceShopDbContext::userVoucherCarts, $signal->data->collectionKey);
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

    /**
     * @param int $epoch Source epoch of the binding
     * @param int $voucherId Catalog choice saved on the source
     */
    private static function addSourceVoucher(int $epoch, int $voucherId): void
    {
        Database::sqlRun(
            'INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) VALUES (?, ?, ?)',
            [self::SOURCE_SESSION, $epoch, $voucherId],
        );
    }

    /**
     * @param string $code Seeded voucher code
     * @return int Persisted voucher ID
     */
    private static function voucherId(string $code): int
    {
        $id = Database::sql('SELECT id FROM voucher WHERE code = ?', [$code])->field('id');
        self::assertNotNull($id);

        return (int)$id;
    }

    /**
     * @param int $userId Account with the voucher slot
     * @return ?int Persisted selection, or null for an empty slot
     */
    private static function accountVoucherId(int $userId): ?int
    {
        $id = Database::sql('SELECT voucher_id FROM user_voucher_cart WHERE user_id = ?', [$userId])->field('voucher_id');

        return $id === null ? null : (int)$id;
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
