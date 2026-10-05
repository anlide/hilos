<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\AccessLog\AccessLogPolicy;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The chat's privacy text keeps no access log and does keep the address of a session (HIL-1174).
 *
 * The text deviates from standard.access_log (the separate log is disabled) and not from
 * standard.session_data, so a sign-in and a connection from a new address write nothing to the
 * log, while the session still keeps the address it last came from.
 *
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class AccessLogTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string ACCEPT_KEY = 'access-log-ak';

    private const string FIRST_ADDRESS = '203.0.113.20';

    private const string SECOND_ADDRESS = '203.0.113.21';

    /**
     * @throws HilosException When the runtime cannot be cleared or the legal catalog is faulty
     */
    public function testTheChatTextKeepsNoLogAndKeepsTheSessionAddress(): void
    {
        $this->bootAgent();

        self::assertFalse(AccessLogPolicy::keepsLog());
        self::assertTrue(AccessLogPolicy::keepsSessionAddress());
    }

    /**
     * A sign-in and a signed-in connection from a new address leave no row, and the session
     * keeps the address it last came from.
     *
     * @throws HilosException When setup or agent signal handling fails
     */
    public function testASignInAndANewAddressWriteNothing(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('Logged nowhere')->id;

        try {
            $this->deliverHandshake($agent, $this->handshake($token, self::FIRST_ADDRESS));
            $this->authenticateSession($agent, $token, $userId, null);
            $this->deliverHandshake($agent, $this->handshake($token, self::SECOND_ADDRESS));

            self::assertSame($userId, Hilos::$db->sessions->findByToken($token)?->userId);
            self::assertSame(self::SECOND_ADDRESS, self::sessionAddress($token));
            self::assertSame(0, self::accessLogRowsOf($userId));
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * @return ChatAgent Agent holding this project's connections
     * @throws HilosException When the runtime cannot be cleared
     */
    private function bootAgent(): ChatAgent
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(StateHilosSessionRotation::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
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
     * @param string $token Session cookie token
     * @param string $clientIp Address the transport gives
     * @return WebSocketHandshakeSignalDTO Handshake payload
     */
    private function handshake(string $token, string $clientIp): WebSocketHandshakeSignalDTO
    {
        return new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: self::ACCEPT_KEY,
            cookies: [],
            clientIp: $clientIp,
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        );
    }

    /**
     * @param string $token Session cookie token
     * @return ?string Address the session keeps, read past every in-memory collection
     * @throws HilosException When the query fails
     */
    private static function sessionAddress(string $token): ?string
    {
        Database::sql('SELECT `ip_address` FROM `hilos_session` WHERE `token` = ?', [$token]);
        $row = Database::row();
        self::assertNotNull($row, 'No connection asked for the sign-in, so the token was not rotated');

        return $row['ip_address'] === null ? null : (string) $row['ip_address'];
    }

    /**
     * @param int $userId Person
     * @return int Access log rows naming the person
     * @throws HilosException When the query fails
     */
    private static function accessLogRowsOf(int $userId): int
    {
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_access_log` WHERE `user_id` = ?', [$userId]);

        return (int) (Database::row()['count'] ?? 0);
    }
}
