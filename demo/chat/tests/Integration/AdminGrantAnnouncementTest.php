<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration test for what an admin:grant reaches: the tab that is already open (HIL-849).
 *
 * The half nobody was watching. That the framework SENDS a session state frame after the write
 * is pinned by a unit case since HIL-644; that the frame ARRIVES - reaches this project, is
 * turned back into a greeting, and hands the browser its new rights without a reload - was
 * proven by nothing. The chat e2e is no witness either: it signs in again first, so it reads a
 * fresh handshake rather than the announcement.
 *
 * Which is why this case sits here rather than beside the seven framework units: a live runtime
 * of connections, a project agent to receive the frame, and a real user row are the whole point
 * of it, and the chat harness already stands all three up.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class AdminGrantAnnouncementTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** @var string Accept key of the tab that is open while the grant is typed */
    private const string OPEN_TAB_ACCEPT_KEY = 'grant-announce-ak';

    /**
     * A grant reaches a tab that was already open, as a greeting saying it is an admin now.
     *
     * @throws HilosException When setup, the command, or a frame that follows it fails
     */
    public function testAGrantReachesAnOpenTabWithoutAReconnect(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;
        $this->deliverHandshake($agent, $this->handshake(self::OPEN_TAB_ACCEPT_KEY, $token));
        $this->authenticateSession($agent, $token, $userId, null);
        // The sign-in greeted this tab already, and that greeting says selfAdmin false; only
        // what the grant sends afterwards can answer the assertion below.
        $this->drainSignals();

        try {
            $this->sessionsLibrary()->onSignalCommand($this->grantCommand($userId), '', '');
            $this->deliverLibraryFrames($agent);

            $response = $this->lastHandshakeResponseFor(self::OPEN_TAB_ACCEPT_KEY);
            $this->assertNotNull($response, 'The open tab is greeted again after the grant');
            $this->assertTrue($response->selfAdmin);
            $this->assertSame($userId, $response->selfId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Builds an admin:grant command request for one user.
     *
     * @param int $userId User to make an administrator
     * @return CommandRequestDTO Grant command request
     */
    private function grantCommand(int $userId): CommandRequestDTO
    {
        return new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: CliCommands::ADMIN_GRANT,
            payload: [
                AdminCommandConstants::FIELD_USER_ID => $userId,
                AdminCommandConstants::FIELD_ADMIN => true,
            ],
        );
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
