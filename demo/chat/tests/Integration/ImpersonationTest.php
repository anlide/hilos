<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\DTO\ImpersonateStopActionDTO;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\DTO\AdminUsers\ImpersonateStartActionDTO;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for admin impersonation. The CLI command handlers (HIL-166)
 * and the in-app transports (HIL-371) — the admin users-table page-action start
 * and the shell agent-action stop — rebind a live admin session to a target user
 * and back over one shared core, guarded by the admin flag, the no-nesting rule,
 * and the self-target rule. Asserts the observable DB session state (bound user +
 * impersonator marker), the re-pointed live connection, and (for the in-app
 * transports) the re-emitted handshake response whose impersonatedBy slot proves
 * the marker-before-rebind ordering.
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
            $agent->onSignalCommand($this->startCommand($token, $targetId), '', '');

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

        $agent->onSignalCommand($this->startCommand($token, $targetId), '', '');

        try {
            $agent->onSignalCommand($this->stopCommand($token), '', '');

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
        $agent->onSignalHandshake($this->handshake('nonadmin-ak', $token), '', '');
        $agent->authenticateSession($token, $userId);
        $targetId = $this->registerUser();

        try {
            $agent->onSignalCommand($this->startCommand($token, $targetId), '', '');

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

        $agent->onSignalCommand($this->startCommand($token, $otherAdminId), '', '');

        try {
            $agent->onSignalCommand($this->startCommand($token, $thirdId), '', '');

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
            $agent->onSignalCommand($this->startCommand($token, $adminId), '', '');

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
            $agent->onSignalCommand($this->stopCommand($token), '', '');

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertSame($adminId, $session?->userId);
            $this->assertNull($session?->impersonatorUserId);
            $this->assertSame($adminId, Hilos::$rt->connections['notimp-ak']->userId);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * The admin users-table page-action start rebinds the session to the target,
     * records the admin marker, and re-emits a handshake response whose
     * impersonatedBy slot names the admin — the browser transport over the same
     * shared core as the CLI command.
     *
     * @throws HilosException When setup or the page action fails
     */
    public function testPageActionStartRebindsAndEmitsImpersonatedBy(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'page-ak', $token);
        $targetId = $this->registerUser();
        $adminName = Hilos::$db->users[$adminId]?->name;
        $this->drainSignals();

        try {
            new AdminUsersPage($agent)->onAction(
                'page-ak',
                ChatSignalConstants::IMPERSONATE_START,
                new ImpersonateStartActionDTO($targetId),
            );

            $session = Hilos::$db->sessions->findByToken($token);
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
     * The shell agent-action stop reverts the impersonating session to its admin,
     * clears the marker, and re-emits a handshake response with a null
     * impersonatedBy slot — the browser transport over the same shared core as the
     * CLI command.
     *
     * @throws HilosException When setup or the agent action fails
     */
    public function testAgentActionStopRevertsAndClearsImpersonatedBy(): void
    {
        $agent = $this->bootAgent();
        $token = RandomHelper::hex(16);
        $adminId = $this->authenticatedAdminSession($agent, 'agent-ak', $token);
        $targetId = $this->registerUser();
        $agent->startImpersonation($token, $targetId);
        $this->drainSignals();

        try {
            $agent->onAgentAction('agent-ak', ChatSignalConstants::IMPERSONATE_STOP, new ImpersonateStopActionDTO());

            $session = Hilos::$db->sessions->findByToken($token);
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
     * Empties the signal-router queue so a later {@see self::lastHandshakeResponseFor()}
     * observes only signals emitted after this point.
     */
    private function drainSignals(): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
            // discard
        }
    }

    /**
     * Drains the signal-router queue and returns the last handshake response
     * targeted at the given connection, or null when none was emitted.
     *
     * @param string $acceptKey Target connection accept key
     * @return ?HandshakeResponseSignalData Last handshake response for the connection
     */
    private function lastHandshakeResponseFor(string $acceptKey): ?HandshakeResponseSignalData
    {
        $found = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData
                && $data->targetAcceptKey === $acceptKey
                && $data->data instanceof HandshakeResponseSignalData) {
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
        $agent->onSignalHandshake($this->handshake($acceptKey, $token), '', '');
        $agent->authenticateSession($token, $adminId);

        return $adminId;
    }

    /**
     * Builds an impersonateStart command request.
     *
     * @param string $token Session cookie token
     * @param int $targetUserId User id to impersonate
     * @return CommandRequestDTO Start command request
     */
    private function startCommand(string $token, int $targetUserId): CommandRequestDTO
    {
        return new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: ChatCommandConstants::IMPERSONATE_START,
            payload: [
                ChatCommandConstants::FIELD_SESSION_TOKEN => $token,
                ChatCommandConstants::FIELD_TARGET_USER_ID => $targetUserId,
            ],
        );
    }

    /**
     * Builds an impersonateStop command request.
     *
     * @param string $token Session cookie token
     * @return CommandRequestDTO Stop command request
     */
    private function stopCommand(string $token): CommandRequestDTO
    {
        return new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: ChatCommandConstants::IMPERSONATE_STOP,
            payload: [
                ChatCommandConstants::FIELD_SESSION_TOKEN => $token,
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
