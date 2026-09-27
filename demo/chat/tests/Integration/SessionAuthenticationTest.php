<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\SessionCookieName;
use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\View\Item\HilosSessionRotation;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the session mechanism (HIL-161, HIL-163, HIL-582).
 *
 * A bare cookie token yields an anonymous session and connection;
 * `authenticateSession` upgrades the live session to a user and
 * `deauthenticateSession` reverts it.
 *
 * Since HIL-582 the upgrade also ROTATES the session onto a fresh token and
 * authenticates only the connection that initiated the login. Both are pinned here,
 * because both are the fix for the same session-fixation attack: a token planted in
 * the browser before the login must stop naming the session, and a socket opened
 * beforehand with that token must not be promoted along with the victim's.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class SessionAuthenticationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /**
     * A new cookie token registers an anonymous session and connection (no user).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testHandshakeCreatesAnonymousSessionAndConnection(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);

        $this->deliverHandshake($agent, $this->handshake('anon-ak', $token));

        try {
            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertNull(Hilos::$rt->connections['anon-ak']->userId);
        } finally {
            $this->reset();
        }
    }

    /**
     * authenticateSession binds the session to a user and re-points its connection.
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testAuthenticateSessionBindsUserAndRepointsConnection(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('upgrade-ak', $token));
        $this->assertNull(Hilos::$rt->connections['upgrade-ak']->userId);

        try {
            $this->authenticateSession($agent, $token, $userId, 'upgrade-ak');

            $this->assertSame($userId, $this->rotatedSession($token)?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['upgrade-ak']->userId);
        } finally {
            $this->reset();
        }
    }

    /**
     * The login moves the session onto a token nobody outside has seen, and the value
     * the browser arrived with stops resolving to a session at all (HIL-582).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testAuthenticateSessionRotatesTheTokenAndAbandonsTheOldOne(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('rotate-ak', $token));

        try {
            $this->authenticateSession($agent, $token, $userId, 'rotate-ak');

            $rotated = $this->rotatedToken();
            $this->assertNotSame($token, $rotated);
            $this->assertNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNotNull(Hilos::$db->sessions->findByToken($rotated));
        } finally {
            $this->reset();
        }
    }

    /**
     * The rotation renames the session, it does not replace it: the row keeps its id and
     * its creation time, so everything hung off the session survives the login (HIL-582).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testTheRotatedSessionIsTheSameRow(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('same-row-ak', $token));
        $before = Hilos::$db->sessions->findByToken($token);
        $sessionId = $before?->id;
        $createdAt = $before?->createdAt;

        try {
            $this->authenticateSession($agent, $token, $userId, 'same-row-ak');

            $after = Hilos::$db->sessions->findByToken($this->rotatedToken());
            $this->assertNotNull($sessionId);
            $this->assertSame($sessionId, $after?->id);
            $this->assertSame($createdAt, $after?->createdAt);
        } finally {
            $this->reset();
        }
    }

    /**
     * The connection that logged in follows the session onto its new token; it is the
     * only one, and the only one authenticated (HIL-582).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testOnlyTheInitiatingConnectionIsAuthenticatedAndRepointed(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('tab-a-ak', $token));
        $this->deliverHandshake($agent, $this->handshake('tab-b-ak', $token));

        try {
            $this->authenticateSession($agent, $token, $userId, 'tab-a-ak');
            $rotated = $this->rotatedToken();

            $this->assertSame($userId, Hilos::$rt->connections['tab-a-ak']->userId);
            $this->assertSame($rotated, Hilos::$rt->connections['tab-a-ak']->sessionToken);

            // The other tab is the shape of the attack: had it been promoted too, a socket
            // opened with a planted token would have been logged in by the victim.
            $this->assertNull(Hilos::$rt->connections['tab-b-ak']->userId);
            $this->assertSame($token, Hilos::$rt->connections['tab-b-ak']->sessionToken);
        } finally {
            $this->reset();
        }
    }

    /**
     * The session's other connections are named for the drop that follows the cookie
     * exchange, and the initiator is not among them (HIL-582).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testTheOtherConnectionsOfTheSessionAreQueuedForTheDrop(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('drop-a-ak', $token));
        $this->deliverHandshake($agent, $this->handshake('drop-b-ak', $token));

        try {
            $this->authenticateSession($agent, $token, $userId, 'drop-a-ak');

            $rotation = $this->rotation();
            $this->assertSame(['drop-b-ak'], $rotation?->acceptKeysToDrop);
        } finally {
            $this->reset();
        }
    }

    /**
     * A caller with no live connection gets no rotation: there is no channel to deliver
     * the ticket on, and no planted token to abandon (HIL-582, the CLI path).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testAnAuthenticateWithoutAnInitiatorKeepsTheToken(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('cli-ak', $token));

        try {
            $this->authenticateSession($agent, $token, $userId, null);

            $this->assertSame($userId, Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertNull($this->rotation());
        } finally {
            $this->reset();
        }
    }

    /**
     * deauthenticateSession reverts the session to anonymous, keeps the row, and
     * re-points its connection back to no user (HIL-163, inverse of authenticate).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testDeauthenticateSessionRevertsUserAndRepointsConnection(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('logout-ak', $token));
        $this->authenticateSession($agent, $token, $userId, 'logout-ak');
        $rotated = $this->rotatedToken();
        $sessionId = Hilos::$db->sessions->findByToken($rotated)?->id;
        Hilos::$rt->hilosSessionRotations->actions->forget($this->rotation()->ticket);
        $this->assertSame($userId, Hilos::$rt->connections['logout-ak']->userId);

        try {
            $this->deauthenticateSession($agent, $rotated);

            $newToken = $this->rotatedToken();
            $this->assertNotSame($rotated, $newToken);
            $this->assertNull(Hilos::$db->sessions->findByToken($rotated));
            $this->assertSame($sessionId, Hilos::$db->sessions->findByToken($newToken)?->id);
            $this->assertNull(Hilos::$db->sessions[$sessionId]->userId);
            $this->assertNull(Hilos::$rt->connections['logout-ak']->userId);
            $this->assertSame($newToken, Hilos::$rt->connections['logout-ak']->sessionToken);
        } finally {
            $this->reset();
        }
    }

    /**
     * Logout on an already-anonymous session is ignored: no error, session stays
     * anonymous, and its connection stays user-less (HIL-163 guest guard).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testDeauthenticateAnonymousSessionIsNoop(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);

        $this->deliverHandshake($agent, $this->handshake('guest-ak', $token));
        $this->assertNull(Hilos::$rt->connections['guest-ak']->userId);

        try {
            $this->deauthenticateSession($agent, $token);

            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertNull(Hilos::$rt->connections['guest-ak']->userId);
        } finally {
            $this->reset();
        }
    }

    /**
     * Logout reaches EVERY connection before its ticket holder reconnects (HIL-1126).
     * With no initiator, the first tab holds the ticket and the rest are queued for a drop.
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testLogoutRepointsEveryConnectionOfSession(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;

        $this->deliverHandshake($agent, $this->handshake('out-a-ak', $token));
        $this->authenticateSession($agent, $token, $userId, 'out-a-ak');
        $rotated = $this->rotatedToken();

        $sessionId = Hilos::$db->sessions->findByToken($rotated)?->id;
        Hilos::$rt->hilosSessionRotations->actions->forget($this->rotation()->ticket);

        // The second tab arrives after the rotation, so it names the session's live token.
        $this->deliverHandshake($agent, $this->handshake('out-b-ak', $rotated));
        $this->authenticateSession($agent, $rotated, $userId, null);
        $this->assertSame($userId, Hilos::$rt->connections['out-a-ak']->userId);

        try {
            $this->deauthenticateSession($agent, $rotated);

            $newToken = $this->rotatedToken();
            $this->assertNotSame($rotated, $newToken);
            $this->assertNull(Hilos::$db->sessions->findByToken($rotated));
            $this->assertSame($sessionId, Hilos::$db->sessions->findByToken($newToken)?->id);
            $this->assertNull(Hilos::$db->sessions[$sessionId]->userId);
            $this->assertSame($newToken, Hilos::$rt->connections['out-a-ak']->sessionToken);
            $this->assertSame($newToken, Hilos::$rt->connections['out-b-ak']->sessionToken);
            $this->assertSame(['out-b-ak'], $this->rotation()?->acceptKeysToDrop);
            $this->assertNull(Hilos::$rt->connections['out-a-ak']->userId);
            $this->assertNull(Hilos::$rt->connections['out-b-ak']->userId);
        } finally {
            $this->reset();
        }
    }

    /**
     * A sign-out abandons the old address and gives the ticket to the tab that asked,
     * even when another tab was registered first (HIL-1126).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testASignOutHandsTheTicketToTheTabThatAskedAndDropsTheRest(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $session->actions->bindUser($userId);
        $sessionId = $session->id;
        $this->deliverHandshake($agent, $this->handshake('first-ak', $token));
        $this->deliverHandshake($agent, $this->handshake('asking-ak', $token));

        try {
            $this->rebindSession($agent, new SessionRebindSignalData(
                sessionToken: $token,
                userId: null,
                initiatorAcceptKey: 'asking-ak',
            ));

            $newToken = $this->rotatedToken();
            $this->assertNotSame($token, $newToken);
            $this->assertNull(Hilos::$db->sessions->findByToken($token));
            $this->assertSame($sessionId, Hilos::$db->sessions->findByToken($newToken)?->id);
            $this->assertNull(Hilos::$db->sessions[$sessionId]->userId);
            $this->assertSame(['first-ak'], $this->rotation()?->acceptKeysToDrop);
            foreach (['first-ak', 'asking-ak'] as $acceptKey) {
                $this->assertNull(Hilos::$rt->connections[$acceptKey]->userId);
                $this->assertSame($newToken, Hilos::$rt->connections[$acceptKey]->sessionToken);
            }
        } finally {
            $this->reset();
        }
    }

    /**
     * Ending a closed browser's session changes its token without issuing an
     * undeliverable ticket (HIL-1126).
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testASignOutWithNoLiveTabRegistersNoRotation(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $session->actions->bindUser($userId);
        $sessionId = $session->id;

        try {
            $this->deauthenticateSession($agent, $token);

            $this->assertNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNotNull(Hilos::$db->sessions[$sessionId]);
            $this->assertNotSame($token, Hilos::$db->sessions[$sessionId]->token);
            $this->assertNull(Hilos::$db->sessions[$sessionId]->userId);
            $this->assertNull($this->rotation());
        } finally {
            $this->reset();
        }
    }

    /**
     * A presented cookie whose row is gone is replaced, not adopted as a guest's address.
     *
     * @throws HilosException When setup or handshake handling fails
     */
    public function testAHandshakeWithADeadCookieIsServedOnANewToken(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);

        try {
            $this->deliverHandshake($agent, $this->handshake('dead-ak', $token, [SessionCookieName::resolve() => $token]));

            $newToken = $this->rotatedToken();
            $this->assertNotSame($token, $newToken);
            $this->assertNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNotNull(Hilos::$db->sessions->findByToken($newToken));
            $this->assertNull(Hilos::$db->sessions->findByToken($newToken)->userId);
            $this->assertSame($newToken, Hilos::$rt->connections['dead-ak']->sessionToken);
            $this->assertSame([], $this->rotation()?->acceptKeysToDrop);
            $this->assertSame(['dead-ak'], $this->rotationRecipients());
        } finally {
            $this->reset();
        }
    }

    /**
     * A lost ticket must not create a replacement-ticket/reconnect loop.
     *
     * @throws HilosException When setup or handshake handling fails
     */
    public function testAHandshakeThatCarriesATicketCookieIsNotReplaced(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $cookieName = SessionCookieName::resolve();

        try {
            $this->deliverHandshake($agent, $this->handshake('ticket-ak', $token, [
                $cookieName => $token,
                SessionRotationTicket::cookieName($cookieName) => 'lost-ticket',
            ]));

            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNull(Hilos::$db->sessions->findByToken($token)->userId);
            $this->assertSame($token, Hilos::$rt->connections['ticket-ak']->sessionToken);
            $this->assertNull($this->rotation());
            $this->assertSame([], $this->rotationRecipients());
        } finally {
            $this->reset();
        }
    }

    /**
     * A new browser uses the token the master just minted, without a second round trip.
     *
     * @throws HilosException When setup or handshake handling fails
     */
    public function testAHandshakeWithoutACookieIsNotReplaced(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);

        try {
            $this->deliverHandshake($agent, $this->handshake('new-ak', $token));

            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNull(Hilos::$db->sessions->findByToken($token)->userId);
            $this->assertSame($token, Hilos::$rt->connections['new-ak']->sessionToken);
            $this->assertNull($this->rotation());
        } finally {
            $this->reset();
        }
    }

    /**
     * A cookie the master did not adopt cannot turn its freshly issued token into a dead cookie.
     *
     * @throws HilosException When setup or handshake handling fails
     */
    public function testAHandshakeWithADifferentCookieKeepsTheMastersToken(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);

        try {
            $this->deliverHandshake($agent, $this->handshake('different-ak', $token, [
                SessionCookieName::resolve() => RandomHelper::hex(16),
            ]));

            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertSame($token, Hilos::$rt->connections['different-ak']->sessionToken);
            $this->assertNull($this->rotation());
        } finally {
            $this->reset();
        }
    }

    /**
     * Expiry gives the ticket to the arriving connection, not to an older live tab.
     *
     * @throws HilosException When setup or handshake handling fails
     */
    public function testAnExpiredSessionFoundAtHandshakeHandsThatConnectionTheTicket(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $session->actions->bindUser($userId);
        $sessionId = $session->id;
        $this->deliverHandshake($agent, $this->handshake('old-ak', $token));
        $session->actions->expire();
        $this->drainSignals();

        try {
            $this->deliverHandshake($agent, $this->handshake('arriving-ak', $token, [SessionCookieName::resolve() => $token]));

            $newToken = $this->rotatedToken();
            $this->assertNotSame($token, $newToken);
            $this->assertNull(Hilos::$db->sessions->findByToken($token));
            $this->assertSame($sessionId, Hilos::$db->sessions->findByToken($newToken)?->id);
            $this->assertNull(Hilos::$db->sessions[$sessionId]->userId);
            foreach (['old-ak', 'arriving-ak'] as $acceptKey) {
                $this->assertNull(Hilos::$rt->connections[$acceptKey]->userId);
                $this->assertSame($newToken, Hilos::$rt->connections[$acceptKey]->sessionToken);
            }
            $this->assertSame(['old-ak'], $this->rotation()?->acceptKeysToDrop);
            $this->assertSame(['arriving-ak'], $this->rotationRecipients());
        } finally {
            $this->reset();
        }
    }

    /**
     * Registers the truth sources and signal router the handshake path needs.
     *
     * @return ChatAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(StateHilosSessionRotation::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $this->reset();

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'listener-ak',
            PageConstants::MAIN,
            [],
        ));

        return new ChatAgent();
    }

    /**
     * Clears the runtime a case leaves behind, connections and pending rotations alike.
     *
     * @throws HilosException When runtime teardown fails
     */
    private function reset(): void
    {
        Hilos::$rt->connections->actions->clear();

        foreach (Hilos::$rt->hilosSessionRotations as $rotation) {
            Hilos::$rt->hilosSessionRotations->actions->forget($rotation->ticket);
        }
    }

    /**
     * Drains the browser frames and names every socket handed a rotation ticket.
     *
     * @return list<string> Ticket recipients, in delivery order
     */
    private function rotationRecipients(): array
    {
        $recipients = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->data instanceof SessionRotateSignalData) {
                $recipients[] = $signal->data->targetAcceptKey;
            }
        }

        return $recipients;
    }

    /**
     * Returns the single rotation the case under test announced, if any.
     *
     * @return ?HilosSessionRotation Announced rotation, or null when none was
     */
    private function rotation(): ?HilosSessionRotation
    {
        foreach (Hilos::$rt->hilosSessionRotations as $rotation) {
            return $rotation;
        }

        return null;
    }

    /**
     * Returns the token the session was rotated onto, failing the case when none was.
     *
     * @return string Rotated session token
     */
    private function rotatedToken(): string
    {
        $rotation = $this->rotation();
        $this->assertNotNull($rotation, 'The transition announced no rotation');

        return $rotation->sessionToken;
    }

    /**
     * Resolves the session behind a pre-login token, following the rotation if there was one.
     *
     * @param string $token Token the browser arrived with
     * @return ?Session Session the login left behind
     */
    private function rotatedSession(string $token): ?Session
    {
        $rotation = $this->rotation();

        return Hilos::$db->sessions->findByToken($rotation?->sessionToken ?? $token);
    }

    /**
     * Builds a handshake signal for an accept key and cookie token.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $token Session cookie token
     * @param array<string, string> $cookies Cookies presented by the browser
     * @return WebSocketHandshakeSignalDTO Handshake payload
     */
    private function handshake(string $acceptKey, string $token, array $cookies = []): WebSocketHandshakeSignalDTO
    {
        return new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: $cookies,
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        );
    }
}
