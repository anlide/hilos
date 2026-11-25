<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Group\GroupMembership;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves what the handshake leaves behind in a demo whose visitors stay nameless.
 *
 * The session is the framework's and the socket is this demo's: the cases drive the handshake
 * the way the daemon does - with a token it has already resolved - and read back the
 * connection row and the handshake response. A visitor gets no account and no name; a
 * session that carries an account binds its socket to that account and says who it is.
 * Every socket is put in the group of the cart it holds now, and the cart's owner is asked
 * to tell it; the group is read back from this worker's mirror, the ask from the queue.
 *
 * Requires the test DB reset (composer run test:db-reset).
 */
final class EcommerceShopAgentHandshakeTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(
            EcommerceShopRtContext::connections,
            TruthSourceKeys::all(),
            self::TEST_AGENT_ID,
        );
        Hilos::$rt->connections->actions->clear();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    /**
     * A new cookie gets an anonymous connection, no account, and a response without one.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testAnonymousHandshakeMintsNoUserAndAnswersWithoutAnAccount(): void
    {
        $token = RandomHelper::hex(16);
        $usersBefore = count(Hilos::$db->users->listAll());

        $this->handshake($token, 'ecommerce-shop-anon-ak');

        self::assertCount($usersBefore, Hilos::$db->users->listAll(), 'A visitor mints no user');

        $connection = Hilos::$rt->connections['ecommerce-shop-anon-ak'];
        self::assertNotNull($connection);
        self::assertNull($connection->userId);
        self::assertSame($token, $connection->sessionToken);

        $response = $this->lastHandshakeResponseFor('ecommerce-shop-anon-ak');
        self::assertNotNull($response, 'The handshake is answered even for a visitor');
        self::assertNull($response->selfId);
        self::assertNull($response->selfName);
    }

    /**
     * A session that gained an account binds its next socket to it and names it.
     *
     * The account is bound the way `admin:create` binds it - onto the session row - and the next
     * handshake on the same cookie is what carries it onto the socket and into the response.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testHandshakeOfAnAccountBindsTheConnectionAndNamesThePerson(): void
    {
        $token = RandomHelper::hex(16);

        $this->handshake($token, 'ecommerce-shop-bind-ak-1');
        $this->drainSignals();

        $admin = Hilos::$db->users->actions->registerAdmin();
        Hilos::$db->sessions->findByToken($token)?->actions->bindUser((int)$admin->id);

        $this->handshake($token, 'ecommerce-shop-bind-ak-2');

        $connection = Hilos::$rt->connections['ecommerce-shop-bind-ak-2'];
        self::assertNotNull($connection);
        self::assertSame((int)$admin->id, $connection->userId);

        $response = $this->lastHandshakeResponseFor('ecommerce-shop-bind-ak-2');
        self::assertNotNull($response);
        self::assertSame((int)$admin->id, $response->selfId);
        self::assertSame($admin->name, $response->selfName);
    }

    /**
     * A guest's socket joins the cart group of its session, and the guest carts library is asked once.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testAGuestHandshakeJoinsTheSessionsCartAndAsksTheGuestCartsLibrary(): void
    {
        $this->handshake(RandomHelper::hex(16), 'ecommerce-shop-cart-guest-ak');
        $sessionId = Hilos::$rt->connections['ecommerce-shop-cart-guest-ak']?->sessionId;
        self::assertNotNull($sessionId, 'A guest handshake opens a session row');

        self::assertSame(
            CartGroup::forSession($sessionId, 0),
            Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-guest-ak', CartGroup::NAME),
        );
        self::assertEquals(
            [[EcommerceShopSignalConstants::GUEST_CART_SEND, new GuestCartSendSignalData($sessionId, 0)]],
            $this->cartAsks(),
        );
    }

    /**
     * A signed-in session's sockets join the person's cart group, and the person's agent is asked once.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testAHandshakeOfAnAccountJoinsThePersonsCartAndAsksThePersonsAgent(): void
    {
        $token = RandomHelper::hex(16);
        $this->handshake($token, 'ecommerce-shop-cart-bind-ak-1');
        $this->drainSignals();
        $admin = Hilos::$db->users->actions->registerAdmin();
        Hilos::$db->sessions->findByToken($token)?->actions->bindUser((int)$admin->id);

        $this->handshake($token, 'ecommerce-shop-cart-bind-ak-2');

        foreach (['ecommerce-shop-cart-bind-ak-1', 'ecommerce-shop-cart-bind-ak-2'] as $acceptKey) {
            self::assertSame(CartGroup::forUser((int)$admin->id), Hilos::$sr->groupSubscriptionName($acceptKey, CartGroup::NAME));
        }
        self::assertEquals(
            [[EcommerceShopSignalConstants::ACCOUNT_CART_SEND, new AccountCartSendSignalData((int)$admin->id)]],
            $this->cartAsks(),
        );
    }

    /**
     * A live guest's socket signing in leaves the session's cart for the person's, and signing out goes back.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testSigningInAndOutMovesTheSocketBetweenTheSessionsCartAndThePersons(): void
    {
        $token = RandomHelper::hex(16);
        $this->handshake($token, 'ecommerce-shop-cart-live-ak');
        $sessionId = Hilos::$rt->connections['ecommerce-shop-cart-live-ak']?->sessionId;
        self::assertNotNull($sessionId);
        $userId = (int)Hilos::$db->users->actions->registerAdmin()->id;
        $this->drainSignals();

        $session = Hilos::$db->sessions[$sessionId];
        $session->actions->bindUser($userId);
        $this->sessionState(new SessionStateSignalData($token, $sessionId, $userId, ['ecommerce-shop-cart-live-ak']));

        self::assertNull(Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forSession($sessionId, 0)));
        self::assertSame(
            CartGroup::forUser($userId),
            Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forUser($userId)),
        );
        self::assertEquals(
            [[EcommerceShopSignalConstants::ACCOUNT_CART_SEND, new AccountCartSendSignalData($userId)]],
            $this->cartAsks(),
        );

        $session->actions->unbindUser();
        $this->sessionState(new SessionStateSignalData($token, $sessionId, null, ['ecommerce-shop-cart-live-ak']));

        self::assertNull(Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forUser($userId)));
        self::assertSame(
            CartGroup::forSession($sessionId, 1),
            Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forSession($sessionId, 1)),
        );
        self::assertEquals(
            [[EcommerceShopSignalConstants::GUEST_CART_SEND, new GuestCartSendSignalData($sessionId, 1)]],
            $this->cartAsks(),
        );
    }

    /** An anonymous browser's replacement session owns a different cart on the same socket. */
    public function testReplacingAnAnonymousSessionMovesTheSocketToTheNewCart(): void
    {
        $acceptKey = 'ecommerce-shop-cart-replace-ak';
        $this->handshake(RandomHelper::hex(16), $acceptKey);
        $connection = Hilos::$rt->connections[$acceptKey];
        self::assertNotNull($connection);
        self::assertNull($connection->userId);
        self::assertNotNull($connection->sessionId);
        $oldGroup = CartGroup::forSession($connection->sessionId, 0);
        self::assertSame($oldGroup, Hilos::$sr->groupSubscriptionName($acceptKey, $oldGroup));
        $replacement = Hilos::$db->sessions->actions->createAnonymous(RandomHelper::hex(16));
        $this->drainSignals();

        $this->sessionState(new SessionStateSignalData(
            $replacement->token,
            $replacement->id,
            null,
            [$acceptKey],
            rotationTicket: 'replacement-ticket',
        ));

        self::assertNull($connection->userId);
        self::assertSame($replacement->id, $connection->sessionId);
        self::assertSame($replacement->token, $connection->sessionToken);
        self::assertNull(Hilos::$sr->groupSubscriptionName($acceptKey, $oldGroup));
        $newGroup = CartGroup::forSession($replacement->id, 0);
        self::assertSame($newGroup, Hilos::$sr->groupSubscriptionName($acceptKey, $newGroup));

        $expected = [
            SignalTypeConstants::GROUP_LEAVE_ALL,
            EcommerceShopSignalConstants::HANDSHAKE_RESPONSE,
            SignalTypeConstants::GROUP_JOIN,
            HilosSignalConstants::HILOS_SESSION_ROTATE,
            EcommerceShopSignalConstants::GUEST_CART_SEND,
        ];
        $sent = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if (in_array($name, $expected, true)) {
                $sent[] = $name;
            }
            if ($name === EcommerceShopSignalConstants::GUEST_CART_SEND) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertEquals(new GuestCartSendSignalData($replacement->id, 0), $signal->data->data);
            }
        }
        self::assertSame($expected, $sent);
    }

    /** Repeated identity and an in-place token rotation preserve memberships. */
    public function testRestatingAnAnonymousSessionOrRotatingItsTokenKeepsGroups(): void
    {
        $acceptKey = 'ecommerce-shop-cart-rotate-ak';
        $token = RandomHelper::hex(16);
        $this->handshake($token, $acceptKey);
        $connection = Hilos::$rt->connections[$acceptKey];
        self::assertNotNull($connection);
        $sessionId = $connection->sessionId;
        self::assertNotNull($sessionId);
        $cartGroup = CartGroup::forSession($sessionId, 0);
        $otherGroup = 'guest-fixture';
        GroupMembership::join($acceptKey, $otherGroup, (new EcommerceShopAgent())->getAgentSignalSource());
        $this->drainSignals();

        foreach ([$token, RandomHelper::hex(16)] as $sessionToken) {
            Database::sqlRun('UPDATE hilos_session SET token = ? WHERE id = ?', [$sessionToken, $sessionId]);
            $this->sessionState(new SessionStateSignalData($sessionToken, $sessionId, null, [$acceptKey]));

            self::assertNull($connection->userId);
            self::assertSame($sessionId, $connection->sessionId);
            self::assertSame($sessionToken, $connection->sessionToken);
            self::assertSame($cartGroup, Hilos::$sr->groupSubscriptionName($acceptKey, $cartGroup));
            self::assertSame($otherGroup, Hilos::$sr->groupSubscriptionName($acceptKey, $otherGroup));
            self::assertEquals(
                [[EcommerceShopSignalConstants::GUEST_CART_SEND, new GuestCartSendSignalData($sessionId, 0)]],
                $this->cartAsks(),
            );
        }
    }

    /** A guest frame moves an existing socket even if the account frame never reached the connections agent. */
    public function testReturningToGuestMovesTheSocketAfterAMissedBindFrame(): void
    {
        $acceptKey = 'ecommerce-shop-cart-epoch-ak';
        $token = RandomHelper::hex(16);
        $this->handshake($token, $acceptKey);
        $sessionId = Hilos::$rt->connections[$acceptKey]?->sessionId;
        self::assertNotNull($sessionId);
        $oldGroup = CartGroup::forSession($sessionId, 0);
        self::assertSame($oldGroup, Hilos::$sr->groupSubscriptionName($acceptKey, CartGroup::NAME));
        $this->drainSignals();

        Database::sqlRun('UPDATE hilos_session SET cart_epoch = 1 WHERE id = ?', [$sessionId]);
        $this->sessionState(new SessionStateSignalData($token, $sessionId, null, [$acceptKey]));

        self::assertNull(Hilos::$rt->connections[$acceptKey]->userId);
        self::assertNull(Hilos::$sr->groupSubscriptionName($acceptKey, $oldGroup));
        self::assertSame(CartGroup::forSession($sessionId, 1), Hilos::$sr->groupSubscriptionName($acceptKey, CartGroup::NAME));
        self::assertEquals(
            [[EcommerceShopSignalConstants::GUEST_CART_SEND, new GuestCartSendSignalData($sessionId, 1)]],
            $this->cartAsks(),
        );
    }

    /**
     * A frame naming neither a session row nor a person puts the socket in no cart group and asks nobody.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testAFrameWithoutASessionRowOrAPersonJoinsNoCartAndAsksNobody(): void
    {
        $this->sessionState(new SessionStateSignalData(RandomHelper::hex(16), null, null, ['ecommerce-shop-cart-none-ak']));

        self::assertNotNull(Hilos::$rt->connections['ecommerce-shop-cart-none-ak'] ?? null, 'The frame reached the socket');
        self::assertNull(Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-none-ak', CartGroup::NAME));
        self::assertSame([], $this->cartAsks());
    }

    /**
     * Hands the connections agent one session-state frame, as the sessions library sends it.
     *
     * @param SessionStateSignalData $frame What the session is now
     * @throws HilosException On database or runtime failure
     */
    private function sessionState(SessionStateSignalData $frame): void
    {
        (new EcommerceShopAgent())->onSignalAgent(new AgentSignalData($frame), '', HilosSignalConstants::HILOS_SESSION_STATE);
    }

    /**
     * Drains the queue and returns the asks the connections agent sent to the carts' owners, in order.
     *
     * @return list<array{string, SignalDataInterface}> Each ask's signal name and payload
     */
    private function cartAsks(): array
    {
        $asks = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            $data = $signal->data;
            if ($data instanceof AgentSignalData
                && in_array($name, [EcommerceShopSignalConstants::GUEST_CART_SEND, EcommerceShopSignalConstants::ACCOUNT_CART_SEND], true)) {
                $asks[] = [$name, $data->data];
            }
        }

        return $asks;
    }

    /**
     * Drives one handshake, the way the daemon does once it has resolved the token.
     *
     * @param string $sessionToken Session token the handshake carries
     * @param string $acceptKey Accept key of the socket shaking hands
     * @throws HilosException On database or runtime failure
     */
    private function handshake(string $sessionToken, string $acceptKey): void
    {
        $this->deliverHandshake(new EcommerceShopAgent(), new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $sessionToken,
        ));
    }
}
