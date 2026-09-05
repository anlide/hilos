<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UsersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\ImpersonateDoneSignalData;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Pages\Users\AbstractHilosUsersPage;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AdminCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for admin impersonation. The CLI command handlers (HIL-166)
 * and the in-app transports (HIL-371) rebind a live admin session to a target user
 * and back over one shared core, guarded by the admin flag, the no-nesting rule,
 * and the self-target rule. Asserts the observable DB session state (bound user +
 * impersonator marker), the re-pointed live connection, and (for the in-app
 * transports) the re-emitted handshake response whose impersonatedBy slot proves
 * the marker-before-rebind ordering.
 *
 * The two commands and the browser STOP are driven at the sessions library, which owns
 * them (HIL-729). The browser START is not: HIL-824 moved its name onto the framework
 * Hilos users page, because only an administrator may take a person over and an ADMIN
 * level is a thing only a page carries. So that one is driven at the page, which forwards
 * {@see HilosSignalConstants::HILOS_IMPERSONATE_REQUEST} to the library and is answered on
 * {@see HilosSignalConstants::HILOS_IMPERSONATE_DONE} - and a refusal reaches it as text on
 * that frame, because the guards now run outside a page.
 *
 * The only thing left in this project either way is the seam answering whether the takeover
 * is allowed. What the chat agent still does is say the result out loud, which is why every
 * case hands it the frames the library queued.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class ImpersonationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /**
     * A successful start rebinds the session (and its connection) to the target
     * and records the admin on the impersonator marker.
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStartRebindsToTargetAndRecordsAdmin(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'start-ak', $token);
        $targetId = $this->registerUser();

        try {
            $this->runCommand($agent, $this->startCommand($token, $targetId));

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($targetId, $session?->userId);
            $this->assertSame($adminId, $session?->impersonatorUserId);
            $this->assertSame($targetId, Hilos::$rt->connections['start-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Stop restores the recorded admin (and its connection) and clears the marker.
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStopRestoresAdminAndClearsMarker(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'stop-ak', $token);
        $targetId = $this->registerUser();

        $this->runCommand($agent, $this->startCommand($token, $targetId));

        try {
            $this->runCommand($agent, $this->stopCommand($token));

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($adminId, $session?->userId);
            $this->assertNull($session?->impersonatorUserId);
            $this->assertSame($adminId, Hilos::$rt->connections['stop-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Start on a non-admin session is rejected: the session stays bound to its
     * user and no marker is recorded.
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStartRejectsNonAdminSession(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = $this->registerUser();
        $this->deliverHandshake($agent, $this->handshake('nonadmin-ak', $token));
        $this->authenticateSession($agent, $token, $userId, null);
        $targetId = $this->registerUser();

        try {
            $this->runCommand($agent, $this->startCommand($token, $targetId));

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($userId, $session?->userId);
            $this->assertNull($session?->impersonatorUserId);
            $this->assertSame($userId, Hilos::$rt->connections['nonadmin-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A second start while already impersonating is rejected by the no-nesting
     * guard, even when impersonating another admin (which is itself allowed).
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStartRejectsNesting(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'nest-ak', $token);
        $otherAdminId = $this->registerAdmin();
        $thirdId = $this->registerUser();

        $this->runCommand($agent, $this->startCommand($token, $otherAdminId));

        try {
            $this->runCommand($agent, $this->startCommand($token, $thirdId));

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($otherAdminId, $session?->userId);
            $this->assertSame($adminId, $session?->impersonatorUserId);
            $this->assertSame($otherAdminId, Hilos::$rt->connections['nest-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Start with the admin as its own target is rejected (no self-impersonation).
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStartRejectsSelfTarget(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'self-ak', $token);

        try {
            $this->runCommand($agent, $this->startCommand($token, $adminId));

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($adminId, $session?->userId);
            $this->assertNull($session?->impersonatorUserId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Stop on a session that is not impersonating is rejected and leaves the
     * bound user untouched.
     *
     * @throws HilosException When setup or command handling fails
     */
    public function testImpersonateStopRejectsWhenNotImpersonating(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'notimp-ak', $token);

        try {
            $this->runCommand($agent, $this->stopCommand($token));

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($adminId, $session?->userId);
            $this->assertNull($session?->impersonatorUserId);
            $this->assertSame($adminId, Hilos::$rt->connections['notimp-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * The browser start reaches the library through the page that holds its name, rebinds
     * the session to the target, records the admin marker, and re-emits a handshake
     * response whose impersonatedBy slot names the admin — the browser transport over the
     * same shared core as the CLI command.
     *
     * Two hops since HIL-824, and the case drives both: the page forwards the request, the
     * library writes. What it proves beyond the command case is that nothing was lost in
     * the move — the same session state, the same marker ordering, the same greeting.
     *
     * @throws HilosException When setup or the action fails
     */
    public function testPageStartRebindsAndEmitsImpersonatedBy(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'page-ak', $token);
        $targetId = $this->registerUser();
        $adminName = Hilos::$db->users[$adminId]?->name;
        $this->drainSignals();

        try {
            $this->runPageStart($agent, 'page-ak', $targetId);

            $session = $this->sessionOf('page-ak');
            $this->assertSame($targetId, $session?->userId);
            $this->assertSame($adminId, $session?->impersonatorUserId);
            $this->assertSame($targetId, Hilos::$rt->connections['page-ak']->userId);

            $response = $this->lastHandshakeResponseFor('page-ak');
            $this->assertNotNull($response);
            $this->assertSame($targetId, $response->selfId);
            $this->assertSame($adminId, $response->impersonatorId);
            $this->assertSame($adminName, $response->impersonatorName);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * A refused takeover comes back to the page as TEXT on the done frame, and the session
     * is left alone.
     *
     * This is the half of the move that could have been lost quietly. While the name was
     * the library's, a guard threw and the dispatcher turned the throw into the fail ack
     * the caller was waiting on. After the move the guards run outside a page, where that
     * hook does not reach, so the reason has to travel as a field — and a page with nothing
     * to say would leave the admin's modal waiting for its own timeout.
     *
     * @throws HilosException When setup or the action fails
     */
    public function testPageStartRefusalTravelsAsTextOnTheDoneFrame(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $userId = $this->registerUser();
        $this->deliverHandshake($agent, $this->handshake('refused-ak', $token));
        $this->authenticateSession($agent, $token, $userId, null);
        $targetId = $this->registerUser();
        $this->drainSignals();

        try {
            $this->runPageStart($agent, 'refused-ak', $targetId);

            $done = $this->lastImpersonateDone();
            $this->assertNotNull($done);
            $this->assertSame('refused-ak', $done->acceptKey);
            $this->assertNotNull($done->error);

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($userId, $session?->userId);
            $this->assertNull($session?->impersonatorUserId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * The name is declared where the lock is, and nowhere else.
     *
     * The lock never travels with the name (HIL-771), so what closes the takeover is
     * whatever page holds it — and the page that does inherits ADMIN. Asserted on the
     * classes rather than through the dispatcher because the dispatcher's own 403 rail is
     * pinned framework-side, in framework/tests/Unit/PageAccessGateTest.php; what can go
     * wrong HERE is the name drifting back onto an agent, where no level would reach it.
     */
    public function testTheTakeoverNameSitsUnderAnAdminPageLevel(): void
    {
        $this->assertArrayHasKey(
            HilosSignalConstants::HILOS_IMPERSONATE_START,
            AbstractHilosUsersPage::ACTIONS,
        );
        $this->assertSame(PageAccessLevel::ADMIN, AbstractHilosUsersPage::ACCESS_LEVEL);
        $this->assertArrayNotHasKey(
            HilosSignalConstants::HILOS_IMPERSONATE_START,
            AbstractSessionsLibraryAgent::AGENT_ACTIONS,
        );
    }

    /**
     * The shell stop action reverts the impersonating session to its admin, clears the
     * marker, and re-emits a handshake response with a null impersonatedBy slot — the
     * browser transport over the same shared core as the CLI command.
     *
     * @throws HilosException When setup or the action fails
     */
    public function testStopActionRevertsAndClearsImpersonatedBy(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'agent-ak', $token);
        $targetId = $this->registerUser();
        $this->runCommand($agent, $this->startCommand($token, $targetId));
        $this->drainSignals();

        try {
            $this->runAction($agent, 'agent-ak', new ImpersonateStopActionDTO());

            $session = $this->sessionOf('agent-ak');
            $this->assertSame($adminId, $session?->userId);
            $this->assertNull($session?->impersonatorUserId);
            $this->assertSame($adminId, Hilos::$rt->connections['agent-ak']->userId);

            $response = $this->lastHandshakeResponseFor('agent-ak');
            $this->assertNotNull($response);
            $this->assertSame($adminId, $response->selfId);
            $this->assertNull($response->impersonatorId);
            $this->assertNull($response->impersonatorName);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Runs one operator command the way two workers run it.
     *
     * The command is the sessions library's since HIL-729 - guards, write and reply all -
     * and what the session became is told to this project on a frame. A case asserting the
     * connection row without delivering that frame would read it as it was before.
     *
     * @param ChatAgent $agent Agent that holds this project's connections
     * @param CommandRequestDTO $command Command request to run
     * @throws HilosException When the command or a frame that follows it fails
     */
    private function runCommand(ChatAgent $agent, CommandRequestDTO $command): void
    {
        $this->sessionsLibrary()->onSignalCommand($command, '', '');
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Runs one browser impersonation action the way the dispatcher runs it.
     *
     * @param ChatAgent $agent Agent that holds this project's connections
     * @param string $acceptKey Accept key of the connection that submitted
     * @param ActionPayloadDTO $dto Parsed action payload naming which of the two it is
     * @throws HilosException When the action or a frame that follows it fails
     */
    private function runAction(ChatAgent $agent, string $acceptKey, ActionPayloadDTO $dto): void
    {
        $this->sessionsLibrary()->onAgentAction($acceptKey, $dto->getAction(), $dto);
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Runs one browser takeover the way the dispatcher runs it since HIL-824: at the page.
     *
     * The page only forwards, so the act is not over when onAction() returns - the write
     * happens one frame later, in the library. {@see IntegrationTestCase::deliverLibraryFrames()}
     * carries that frame, because the request is one of the names the library declares.
     *
     * @param ChatAgent $agent Agent that holds this project's connections
     * @param string $acceptKey Accept key of the connection that submitted
     * @param int $targetUserId User id the session asks to act as
     * @throws HilosException When the action or a frame that follows it fails
     */
    private function runPageStart(ChatAgent $agent, string $acceptKey, int $targetUserId): void
    {
        $page = new UsersPage(new DemoHilosAgent());
        $page->onAction(
            $acceptKey,
            HilosSignalConstants::HILOS_IMPERSONATE_START,
            new ImpersonateStartActionDTO($targetUserId),
        );
        $this->deliverLibraryFrames($agent);
    }

    /**
     * Drains the queue and returns the last takeover outcome the library sent back.
     *
     * The page is not driven with it, so what a case asserts here is the frame's own
     * contents - that a reason travelled at all, and to whom. Turning it into an ack is the
     * page's own three branches, and the general rail under them is pinned in
     * framework/tests/Unit/Auth/Session/ImpersonationTwoStepTest.php: the answer frame is
     * declared on the page, so it arrives where the deferred submit is waiting.
     *
     * @return ?ImpersonateDoneSignalData Last done frame, or null when none was sent
     */
    private function lastImpersonateDone(): ?ImpersonateDoneSignalData
    {
        $found = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_IMPERSONATE_DONE
                && $data instanceof AgentSignalData
                && $data->data instanceof ImpersonateDoneSignalData) {
                $found = $data->data;
            }
        }

        return $found;
    }

    /**
     * Registers a fresh non-admin user and returns its id.
     *
     * @return int New user id
     * @throws HilosException When the user write fails
     */
    private function registerUser(): int
    {
        return (int) Hilos::$db->users->actions->createWithName('User')->id;
    }

    /**
     * Registers a fresh user and flips its admin flag on.
     *
     * @return int New admin user id
     * @throws HilosException When the user write fails
     */
    private function registerAdmin(): int
    {
        $userId = $this->registerUser();
        Hilos::$db->users[$userId]->actions->setAdmin(true);

        return $userId;
    }

    /**
     * Boots the agent, opens a connection for the token, and binds it to a fresh
     * admin user.
     *
     * @param ChatAgent $agent Agent under test
     * @param string $acceptKey WebSocket accept key
     * @param string $token Session cookie token
     * @return int Bound admin user id
     * @throws HilosException When setup or agent signal handling fails
     */
    private function authenticatedAdminSession(ChatAgent $agent, string $acceptKey, string $token): int
    {
        $adminId = $this->registerAdmin();
        $this->deliverHandshake($agent, $this->handshake($acceptKey, $token));
        $this->authenticateSession($agent, $token, $adminId, null);

        return $adminId;
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
     * Builds an impersonate:stop command request.
     *
     * @param string $token Session cookie token
     * @return CommandRequestDTO Stop command request
     */
    private function stopCommand(string $token): CommandRequestDTO
    {
        return new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: CliCommands::IMPERSONATE_STOP,
            payload: [
                AdminCommandConstants::FIELD_SESSION_TOKEN => $token,
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
        RtTruthSourceRegistry::register(ChatRtContext::userStates, TruthSourceKeys::all(), self::TEST_AGENT_ID);
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
