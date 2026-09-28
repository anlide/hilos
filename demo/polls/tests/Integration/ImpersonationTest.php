<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Agents\PollsAgent;
use Demo\Polls\Hilos;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves an administrator of this demo can take a person over and come back (HIL-1197).
 *
 * Until HIL-1197 the button was drawn here and every takeover was refused: the check whether
 * one person may take another over was a seam only the chat demo answered. It is the
 * framework's now, over `hilos_user`, so this demo answers it the way chat does - an
 * administrator may, anybody else is refused as "not an admin session" - and nothing of it
 * lives in this project. What the cases pin is that the whole path holds here: the session
 * moves onto the person with the administrator on its marker, and the greeting the tab gets
 * names the administrator behind the takeover, which is what draws the strip.
 *
 * Requires the test DB reset (composer run test:db-reset).
 */
final class ImpersonationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(PollsRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
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
     * An administrator's session moves onto the person, and the tab is told who is behind it.
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStartRebindsToTargetAndNamesTheAdministrator(): void
    {
        $agent = new PollsAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->signedInSession($agent, 'start-ak', $token, admin: true);
        $adminName = Hilos::$db->users[$adminId]?->name;
        $targetId = (int)Hilos::$db->users->actions->createWithName('Ada')->id;
        $this->drainSignals();

        $this->runCommand($agent, $this->startCommand($token, $targetId));

        $session = Hilos::$db->sessions->findByToken($token);
        self::assertSame($targetId, $session?->userId);
        self::assertSame($adminId, $session?->impersonatorUserId);
        self::assertSame($targetId, Hilos::$rt->connections['start-ak']?->userId);

        $response = $this->lastHandshakeResponseFor('start-ak');
        self::assertNotNull($response);
        self::assertSame($targetId, $response->selfId);
        self::assertSame($adminId, $response->impersonatorId);
        self::assertSame($adminName, $response->impersonatorName);
    }

    /**
     * A person who is no administrator is refused, and their session stays as it was.
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStartRefusesANonAdminSession(): void
    {
        $agent = new PollsAgent();
        $token = RandomHelper::hex(16);
        $userId = $this->signedInSession($agent, 'nonadmin-ak', $token, admin: false);
        $targetId = (int)Hilos::$db->users->actions->createWithName('Ada')->id;
        $this->drainSignals();

        $this->runCommand($agent, $this->startCommand($token, $targetId));

        $reply = $this->lastCommandReply();
        self::assertNotNull($reply);
        self::assertFalse($reply->isOk());
        self::assertSame('Session is not an admin session', $reply->payload[CommandConstants::FIELD_MESSAGE]);

        $session = Hilos::$db->sessions->findByToken($token);
        self::assertSame($userId, $session?->userId);
        self::assertNull($session?->impersonatorUserId);
        self::assertSame($userId, Hilos::$rt->connections['nonadmin-ak']?->userId);
    }

    /**
     * The browser's stop returns the session to the administrator, and the strip goes away.
     *
     * @throws HilosException When setup or the action fails
     */
    public function testStopActionRevertsAndClearsTheImpersonator(): void
    {
        $agent = new PollsAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->signedInSession($agent, 'stop-ak', $token, admin: true);
        $targetId = (int)Hilos::$db->users->actions->createWithName('Ada')->id;
        $this->runCommand($agent, $this->startCommand($token, $targetId));
        $this->drainSignals();

        $this->sessionsLibrary()->onAgentAction(
            'stop-ak',
            HilosSignalConstants::HILOS_IMPERSONATE_STOP,
            new ImpersonateStopActionDTO(),
        );
        $this->deliverLibraryFrames($agent);

        $connection = Hilos::$rt->connections['stop-ak'];
        self::assertSame($adminId, $connection?->userId);
        $session = Hilos::$db->sessions->findByToken((string)$connection?->sessionToken);
        self::assertSame($adminId, $session?->userId);
        self::assertNull($session?->impersonatorUserId);

        $response = $this->lastHandshakeResponseFor('stop-ak');
        self::assertNotNull($response);
        self::assertSame($adminId, $response->selfId);
        self::assertNull($response->impersonatorId);
        self::assertNull($response->impersonatorName);
    }

    /**
     * Opens one socket on a session already signed in as a fresh person.
     *
     * The session is bound before the handshake, the way a browser comes back with the cookie
     * of a sign-in it made earlier, so the connection row carries the person from the start.
     *
     * @param PollsAgent $agent Agent that holds this project's connections
     * @param string $acceptKey WebSocket accept key
     * @param string $token Session cookie token
     * @param bool $admin Whether the person is an administrator
     * @return int Id of the signed-in person
     * @throws HilosException When a row or the handshake cannot be written
     */
    private function signedInSession(PollsAgent $agent, string $acceptKey, string $token, bool $admin): int
    {
        $user = $admin
            ? Hilos::$db->users->actions->registerAdmin()
            : Hilos::$db->users->actions->createWithName('Grace');
        $userId = (int)$user->id;
        Hilos::$db->sessions->actions->createAnonymous($token);
        Hilos::$db->sessions->findByToken($token)?->actions->bindUser($userId);

        $this->deliverHandshake($agent, new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        ));

        return $userId;
    }

    /**
     * Runs one operator command the way two workers run it: the library, then the frames it queued.
     *
     * @param PollsAgent $agent Agent that holds this project's connections
     * @param CommandRequestDTO $command Command request to run
     * @throws HilosException When the command or a frame that follows it fails
     */
    private function runCommand(PollsAgent $agent, CommandRequestDTO $command): void
    {
        $this->sessionsLibrary()->onSignalCommand($command, '', '');
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Builds an impersonate:start command request.
     *
     * @param string $token Session cookie token
     * @param int $targetUserId User id to impersonate
     * @return CommandRequestDTO Start command request
     */
    private function startCommand(string $token, int $targetUserId): CommandRequestDTO
    {
        return new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: CliCommands::IMPERSONATE_START,
            payload: [
                AdminCommandConstants::FIELD_SESSION_TOKEN => $token,
                AdminCommandConstants::FIELD_TARGET_USER_ID => $targetUserId,
            ],
        );
    }

    /**
     * Drains the queue and returns the last command reply in it.
     *
     * @return ?CommandReplyDTO Last reply queued, or null when none was
     */
    private function lastCommandReply(): ?CommandReplyDTO
    {
        $found = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $found = $signal->data;
            }
        }

        return $found;
    }
}
