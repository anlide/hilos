<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the session mechanism (HIL-161): a bare cookie token
 * yields an anonymous session and connection, and authenticateSession upgrades
 * the live connection to an authenticated user. deauthenticateSession (HIL-163)
 * reverts that upgrade back to anonymous.
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

        $agent->onSignalHandshake($this->handshake('anon-ak', $token), '', '');

        try {
            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertNull(Hilos::$rt->connections['anon-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
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

        $agent->onSignalHandshake($this->handshake('upgrade-ak', $token), '', '');
        $this->assertNull(Hilos::$rt->connections['upgrade-ak']->userId);

        try {
            $agent->authenticateSession($token, $userId);

            $this->assertSame($userId, Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertSame($userId, Hilos::$rt->connections['upgrade-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
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

        $agent->onSignalHandshake($this->handshake('logout-ak', $token), '', '');
        $agent->authenticateSession($token, $userId);
        $this->assertSame($userId, Hilos::$rt->connections['logout-ak']->userId);

        try {
            $agent->deauthenticateSession($token);

            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertNull(Hilos::$rt->connections['logout-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
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

        $agent->onSignalHandshake($this->handshake('guest-ak', $token), '', '');
        $this->assertNull(Hilos::$rt->connections['guest-ak']->userId);

        try {
            $agent->deauthenticateSession($token);

            $this->assertNotNull(Hilos::$db->sessions->findByToken($token));
            $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
            $this->assertNull(Hilos::$rt->connections['guest-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
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
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

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
     * Builds a handshake signal for an accept key and cookie token.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $token Session cookie token
     * @return WebSocketHandshakeSignalDTO Handshake payload
     */
    private function handshake(string $acceptKey, string $token): WebSocketHandshakeSignalDTO
    {
        return new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        );
    }
}
