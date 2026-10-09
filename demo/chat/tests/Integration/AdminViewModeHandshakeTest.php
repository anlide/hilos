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
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration test for the node's admin view mode on the session response (HIL-1253).
 *
 * The browser derives what the admin section is to it - full, view or none - from two facts of one
 * frame: the admin flag and the mode of the node. The flag has ridden the response since HIL-361;
 * this pins that the mode rides beside it on the real send path of the chat demo, stamped by the
 * framework: on the anonymous greeting, which is the guest the mode opens the section to, and on the
 * greeting a grant sends to a tab that is already open.
 *
 * The mode is turned on here the way the lever turns it on - the node's runtime row, not the variable.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class AdminViewModeHandshakeTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'admin-view-mode-handshake-test';

    /** @var string Accept key of the tab being greeted */
    private const string TAB_ACCEPT_KEY = 'admin-view-mode-handshake-ak';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT_ID);
        parent::tearDown();
    }

    /**
     * An anonymous tab on a node in the view mode is told so on its greeting.
     *
     * @throws HilosException When setup or the handshake fails
     */
    public function testAnAnonymousGreetingCarriesTheModeWhenItIsOn(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $agent = $this->bootAgent();

        $this->deliverHandshake($agent, $this->handshake(RandomHelper::hex(16)));

        $response = $this->lastHandshakeResponseFor(self::TAB_ACCEPT_KEY);
        $this->assertNotNull($response, 'The tab is greeted');
        $this->assertNull($response->selfId);
        $this->assertTrue($response->adminViewMode);
        $this->assertSame(
            ['switchingEnabled' => true, 'defaultTheme' => ThemeSettingsCatalog::SYSTEM],
            $response->themeSettings,
        );
    }

    /**
     * With the mode off the greeting says off rather than nothing: the stamp ran.
     *
     * @throws HilosException When setup or the handshake fails
     */
    public function testAnAnonymousGreetingCarriesTheModeOffWhenItIsOff(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        $agent = $this->bootAgent();

        $this->deliverHandshake($agent, $this->handshake(RandomHelper::hex(16)));

        $response = $this->lastHandshakeResponseFor(self::TAB_ACCEPT_KEY);
        $this->assertNotNull($response, 'The tab is greeted');
        $this->assertFalse($response->adminViewMode);
    }

    /**
     * A grant greets an open tab again with both facts: the person is an admin now, and the node still looks.
     *
     * @throws HilosException When setup, the command, or a frame that follows it fails
     */
    public function testAGrantGreetsAnOpenTabWithTheFlagAndTheModeTogether(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = (int) Hilos::$db->users->actions->createWithName('Olena Kovalenko')->id;
        $this->deliverHandshake($agent, $this->handshake($token));
        $this->authenticateSession($agent, $token, $userId, null);
        // The sign-in greeted this tab already; only what the grant sends afterwards may answer below.
        $this->drainSignals();

        $this->sessionsLibrary()->onSignalCommand($this->grantCommand($userId), '', '');
        $this->deliverPersonAgentFrames();
        $this->deliverLibraryFrames($agent);

        $response = $this->lastHandshakeResponseFor(self::TAB_ACCEPT_KEY);
        $this->assertNotNull($response, 'The open tab is greeted again after the grant');
        $this->assertSame($userId, $response->selfId);
        $this->assertTrue($response->selfAdmin);
        $this->assertTrue($response->adminViewMode);
        $this->assertSame(
            ['switchingEnabled' => true, 'defaultTheme' => ThemeSettingsCatalog::SYSTEM],
            $response->themeSettings,
        );
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
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
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
     * Builds a handshake signal for the tab under test.
     *
     * @param string $token Session cookie token
     * @return WebSocketHandshakeSignalDTO Handshake payload
     */
    private function handshake(string $token): WebSocketHandshakeSignalDTO
    {
        return new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: self::TAB_ACCEPT_KEY,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        );
    }
}
