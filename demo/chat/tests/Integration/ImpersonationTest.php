<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Constants\CliCommands;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\HilosException;
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
 * All four ways in are driven at the SESSIONS LIBRARY since HIL-729: both commands and
 * both browser actions are its own, and the only thing left in this project is the seam
 * answering whether the takeover is allowed. What the chat agent still does is say the
 * result out loud, which is why every case hands it the frames the library queued.
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
     * The browser start action rebinds the session to the target, records the admin
     * marker, and re-emits a handshake response whose impersonatedBy slot names the
     * admin — the browser transport over the same shared core as the CLI command.
     *
     * Page-independent since HIL-729, though the control that sends it still sits on the
     * admin users table: the takeover moves the person off that page in the very next
     * frame, so no page can be the owner.
     *
     * @throws HilosException When setup or the action fails
     */
    public function testStartActionRebindsAndEmitsImpersonatedBy(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'page-ak', $token);
        $targetId = $this->registerUser();
        $adminName = Hilos::$db->users[$adminId]?->name;
        $this->drainSignals();

        try {
            $this->runAction($agent, 'page-ak', new ImpersonateStartActionDTO($targetId));

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
