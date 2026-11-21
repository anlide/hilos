<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
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
            CartGroup::forSession($sessionId),
            Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-guest-ak', CartGroup::NAME),
        );
        self::assertEquals(
            [[EcommerceShopSignalConstants::GUEST_CART_SEND, new GuestCartSendSignalData($sessionId)]],
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

        $this->sessionState(new SessionStateSignalData($token, $sessionId, $userId, ['ecommerce-shop-cart-live-ak']));

        self::assertNull(Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forSession($sessionId)));
        self::assertSame(
            CartGroup::forUser($userId),
            Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forUser($userId)),
        );
        self::assertEquals(
            [[EcommerceShopSignalConstants::ACCOUNT_CART_SEND, new AccountCartSendSignalData($userId)]],
            $this->cartAsks(),
        );

        $this->sessionState(new SessionStateSignalData($token, $sessionId, null, ['ecommerce-shop-cart-live-ak']));

        self::assertNull(Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forUser($userId)));
        self::assertSame(
            CartGroup::forSession($sessionId),
            Hilos::$sr->groupSubscriptionName('ecommerce-shop-cart-live-ak', CartGroup::forSession($sessionId)),
        );
        self::assertEquals(
            [[EcommerceShopSignalConstants::GUEST_CART_SEND, new GuestCartSendSignalData($sessionId)]],
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
