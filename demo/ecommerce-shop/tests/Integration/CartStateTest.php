<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\DatabaseNames;
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

    /** @var list<int> People the case created, removed after it */
    private array $userIds = [];

    /** @var list<int> Catalog vouchers the case created, removed after it */
    private array $voucherIds = [];

    private ?int $guestSessionId = null;

    private ?GuestCartsLibraryAgent $carts = null;

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
            Database::sqlRun('DELETE FROM `session_product_cart` WHERE `session_id` IN (?, ?)', [self::SESSION, self::OTHER_SESSION]);
            if ($this->guestSessionId !== null) {
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
            Hilos::$sr = null;
            parent::tearDown();
        }
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
            self::assertInstanceOf(CartStateSignalData::class, $data->data);
            $states[] = [$data->targetGroup, $data->data->toArray()];
        }

        return $states;
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
     */
    private static function guestLine(int $sessionId, int $productId, int $quantity): void
    {
        Database::sqlRun(
            'INSERT INTO `session_product_cart` (`session_id`, `product_id`, `quantity`) VALUES (?, ?, ?)',
            [$sessionId, $productId, $quantity],
        );
    }

    /**
     * Writes a line of a person's cart past the person's agent.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Product of the line
     * @param int $quantity Units the line holds, one at least
     */
    private static function accountLine(int $userId, int $productId, int $quantity): void
    {
        Database::sqlRun(
            'INSERT INTO `user_product_cart` (`user_id`, `product_id`, `quantity`) VALUES (?, ?, ?)',
            [$userId, $productId, $quantity],
        );
    }
}
