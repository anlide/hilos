<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\CheckoutPage;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Testing\IntegrationTest;

/** Checkout subscription, live facts and identity handoff through the production worker. */
#[IntegrationTest]
final class CheckoutPageTest extends CartPageEnvironment
{
    /** An account gets the same cart summary on both pages, with the checkout response carrying only cart data. */
    public function testFirstAnswerMatchesTheAccountCart(): void
    {
        $userId = self::person();
        $sessionId = self::storedSession($userId);
        $firstProduct = self::product(ProductKind::STOCK);
        $secondProduct = self::product(ProductKind::STOCK);
        $firstLine = self::accountLine($userId, $firstProduct, 2);
        $secondLine = self::accountLine($userId, $secondProduct, 1);
        $warehouse = self::warehouse();
        self::stock($firstProduct, $warehouse, 2);
        self::stock($secondProduct, $warehouse, 1);
        $voucher = $this->voucher('CHECKOUT20', 20);
        self::accountVoucher($userId, $voucher->id);
        self::connect(self::FIRST, $userId, $sessionId, Hilos::$db->sessions[$sessionId]->token);
        self::connect(self::SECOND, $userId, $sessionId);
        $owner = new UserAgent((string) $userId);

        $this->subscribe($owner, self::SECOND);
        $cart = $this->cart(self::SECOND);

        $this->subscribeTrackedPage($owner, self::FIRST . ':1', CheckoutPage::PAGE);
        $reply = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame(CheckoutPage::PAGE, $reply->pageKey);
        self::assertNull($reply->pageSubscriptionProof);
        $wire = PageResponseSignalData::fromArray($reply->toArray())->payload->data;
        self::assertSame([CheckoutPage::DATA_CART => $cart], $wire);
        self::assertSame($userId, $cart['userId']);
        self::assertNull($cart['guestCartKey']);
        self::assertSame([$firstLine, $secondLine], array_column($cart['lines'], 'lineId'));
        self::assertSame(['id' => $voucher->id, 'code' => 'CHECKOUT20', 'active' => true, 'discount' => '7200'], $cart['voucher']);
        self::assertSame('28800', $cart['total']);
    }

    /** The guest fallback answers the access refusal even when the session holds a cart. */
    public function testGuestReceivesUnauthorizedWithoutCartData(): void
    {
        $sessionId = self::storedSession();
        self::guestLine($sessionId, self::product(ProductKind::STOCK), 1);
        self::connect(self::FIRST, null, $sessionId);

        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST, CheckoutPage::PAGE);

        $this->assertGuestRefusal();
    }

    /** Signing in and out hands the same checkout subscription to the current owner. */
    public function testIdentityReassessmentAnswersCheckoutAfterSignInAndSignOut(): void
    {
        $sessionId = self::storedSession();
        $userId = self::person();
        $guestProduct = self::product(ProductKind::STOCK);
        $accountProduct = self::product(ProductKind::STOCK);
        self::guestLine($sessionId, $guestProduct, 1);
        $accountLine = self::accountLine($userId, $accountProduct, 2);
        $warehouse = self::warehouse();
        self::stock($guestProduct, $warehouse, 5);
        self::stock($accountProduct, $warehouse, 5);
        $voucher = $this->voucher('CHECKOUTHANDOFF25', 25);
        self::accountVoucher($userId, $voucher->id);
        self::connect(self::FIRST, null, $sessionId);
        $guest = new GuestCartsLibraryAgent();
        $account = new UserAgent((string) $userId);
        $this->subscribe($guest, self::FIRST, CheckoutPage::PAGE);
        $this->assertGuestRefusal();

        self::bindFirstUser($userId);
        $this->handoff($guest, $account, CheckoutPage::PAGE);
        $reply = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame(CheckoutPage::PAGE, $reply->pageKey);
        self::assertNull($reply->pageSubscriptionProof);
        $wire = PageResponseSignalData::fromArray($reply->toArray())->payload->data;
        self::assertSame([CheckoutPage::DATA_CART], array_keys($wire));
        $cart = $wire[CheckoutPage::DATA_CART];
        self::assertSame($userId, $cart['userId']);
        self::assertNull($cart['guestCartKey']);
        self::assertSame([$accountLine], array_column($cart['lines'], 'lineId'));
        self::assertSame([$accountProduct], array_column($cart['lines'], 'productId'));
        self::assertSame([2], array_column($cart['lines'], 'quantity'));
        self::assertSame('24000', $cart['itemsTotal']);
        self::assertSame(['id' => $voucher->id, 'code' => 'CHECKOUTHANDOFF25', 'active' => true, 'discount' => '6000'], $cart['voucher']);
        self::assertSame('18000', $cart['total']);
        $subscription = Hilos::$sr->pageSubscription(self::FIRST);
        self::assertSame(CheckoutPage::PAGE, $subscription?->page);
        self::assertSame(HilosAgentType::HILOS_USER, $subscription->agentType);
        self::assertSame((string) $userId, $subscription->agentIndex);

        self::bindFirstUser(null);
        $this->handoff($account, $guest, CheckoutPage::PAGE);
        $this->assertGuestRefusal();
        $subscription = Hilos::$sr->pageSubscription(self::FIRST);
        self::assertSame(CheckoutPage::PAGE, $subscription?->page);
        self::assertSame(AgentType::GUEST_CARTS_LIBRARY, $subscription->agentType);
        self::assertNull($subscription->agentIndex);
    }

    /** Each matching account cart fact can refresh the open checkout on its own. */
    public function testAccountCartFactsRefreshCheckout(): void
    {
        $userId = self::person();
        $product = self::product(ProductKind::STOCK);
        $lineId = self::accountLine($userId, $product, 2);
        self::stock($product, self::warehouse(), 5);
        $stockId = Database::lastInsertId();
        $voucher = $this->voucher('CHECKOUTLIVE10', 10);
        self::accountVoucher($userId, $voucher->id);
        self::connect(self::FIRST, $userId, self::storedSession($userId));
        $this->subscribe(new UserAgent((string) $userId), self::FIRST, CheckoutPage::PAGE);
        self::assertSame('21600', $this->cart(self::FIRST, CheckoutPage::PAGE)['total']);

        self::assertSame([], $this->flushCartFacts(self::productPriceFact($product, 10_000)));
        $cart = $this->cart(self::FIRST, CheckoutPage::PAGE);
        self::assertSame(10_000, $cart['lines'][0]['price']);
        self::assertSame('18000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(self::stockUnitsFact($stockId, 3)));
        self::assertSame(3, $this->cart(self::FIRST, CheckoutPage::PAGE)['lines'][0]['availableQuantity']);

        Database::sql('UPDATE user_product_cart SET quantity = ? WHERE id = ?', [3, $lineId]);
        self::assertSame([], $this->flushCartFacts(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::userProductCarts, (string) $lineId, [UserProductCart::quantity => 3],
        ))));
        $cart = $this->cart(self::FIRST, CheckoutPage::PAGE);
        self::assertSame(3, $cart['lines'][0]['quantity']);
        self::assertSame('27000', $cart['total']);

        self::assertSame([], $this->flushCartFacts(...$this->voucherFacts(static fn () => $voucher->actions->setActive(false))));
        $cart = $this->cart(self::FIRST, CheckoutPage::PAGE);
        self::assertFalse($cart['voucher']['active']);
        self::assertSame('30000', $cart['total']);
    }

    /** Changes to the refused connection's guest cart and its catalog product send no checkout frame. */
    public function testGuestCartFactsLeaveRefusedCheckoutAlone(): void
    {
        $sessionId = self::storedSession();
        $product = self::product(ProductKind::STOCK);
        $lineId = self::guestLine($sessionId, $product, 1);
        self::connect(self::FIRST, null, $sessionId);
        $this->subscribe(new GuestCartsLibraryAgent(), self::FIRST, CheckoutPage::PAGE);
        $this->assertGuestRefusal();
        self::assertSame(AgentType::GUEST_CARTS_LIBRARY, Hilos::$sr->pageSubscription(self::FIRST)?->agentType);

        Database::sql('UPDATE session_product_cart SET quantity = ? WHERE id = ?', [2, $lineId]);
        self::assertSame([], $this->flushCartFacts(
            new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
                EcommerceShopDbContext::sessionProductCarts, (string) $lineId, [SessionProductCart::quantity => 2],
            )),
            self::productPriceFact($product, 9000),
        ));
        self::assertSame([], $this->takePageFrames());
    }

    /** Checks the single public access error emitted for the guest connection. */
    private function assertGuestRefusal(): void
    {
        $reply = $this->pageReply(self::FIRST);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $reply);
        self::assertSame(CheckoutPage::PAGE, $reply->page);
        self::assertSame(HttpConstants::HTTP_UNAUTHORIZED, $reply->httpCode);
        self::assertSame('unauthorized', $reply->errorCode);
    }
}
