<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\CLI\DTO\GuestCartSetQuantityCommandData;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
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
use Hilos\Constants\CommandConstants;
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
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\TimeHelper;
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
