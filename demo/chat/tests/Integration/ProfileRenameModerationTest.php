<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatEventType;
use Hilos\Auth\Library\DTO\ProfileNameSetActionDTO;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\SignalConstants;
use Hilos\Database\Database;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for user-initiated rename moderation.
 */
final class ProfileRenameModerationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(
            HilosModerationCheck::RT_COLLECTION,
            TruthSourceKeys::set(HilosAgentType::HILOS_USERS_LIBRARY),
            self::TEST_AGENT_ID,
        );
    }

    protected function tearDown(): void
    {
        foreach (Hilos::$rt->hilosModerationChecks as $check) {
            if ($check->kind === ModerationKindKey::PROFILE_NAME) {
                $check->actions->forget();
            }
        }
        parent::tearDown();
    }

    public function testRenameActionStartsModerationWithoutChangingUserName(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            $oldName = $user->name;
            ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
            Hilos::initSignalRouter(new ChatSignalRouter());
            Hilos::initBrowser();
            $agent = new ChatAgent();
            $token = RandomHelper::hex(16);
            $this->deliverHandshake($agent, new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'rename-start-ak',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: $token,
            ));
            $this->authenticateSession($agent, $token, (int)$user->id, null);
            $session = $this->sessionOf('rename-start-ak');
            $this->assertNotNull($session);
            Hilos::$db->stepUps->actions->confirm(
                ProtectedModeRuntime::hashSessionToken($session->token),
                (int)$user->id,
                StepUpOperationKey::CHANGE_NAME,
                date('Y-m-d H:i:s', time() + 3600),
            );

            ExecutionContext::setCurrentAcceptKey('rename-start-ak');
            $this->usersLibrary()->onAgentAction(
                'rename-start-ak',
                HilosSignalConstants::PROFILE_NAME_SET,
                new ProfileNameSetActionDTO('Alice'),
            );

            $this->assertSame($oldName, Hilos::$db->users[$user->id]->name);
            $check = Hilos::$rt->hilosModerationChecks->find('rename-start-ak', ModerationKindKey::PROFILE_NAME);
            $this->assertNotNull($check);
            $this->assertSame('Alice', $check->text);
            $this->assertSame($user->id, $check->userId);
            $this->assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $check->verdictTo);
            $this->assertSame(0, $this->deliverPersonAgentFrames());
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testApprovedRenameModerationResultRenamesUser(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            $oldName = $user->name;
            Hilos::$rt->connections->actions->register('rename-approve-ak', $user->id);
            Hilos::$rt->hilosModerationChecks->actions->open(
                'rename-approve-ak', $user->id, ModerationKindKey::PROFILE_NAME, text: 'Alice',
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchRenameModerationVerdict(
                new ModerationVerdictSignalData(
                    acceptKey: 'rename-approve-ak',
                    kind: ModerationKindKey::PROFILE_NAME,
                    requestId: Hilos::$rt->hilosModerationChecks->find('rename-approve-ak', ModerationKindKey::PROFILE_NAME)->requestId,
                    allow: true,
                    reason: 'ok',
                ),
            );

            $this->assertSame('Alice', Hilos::$db->users[$user->id]->name);
            $this->assertNull(Hilos::$rt->hilosModerationChecks->find('rename-approve-ak', ModerationKindKey::PROFILE_NAME));
            $this->assertUserRenamedEventExists($user->id, $oldName, 'Alice');

            // Success is state-driven: the renamed user fans out over the
            // self-connection data, so no explicit success ack is queued.
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testApprovedRenameOfAMergedAccountIsRefusedWithoutAHop(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $survivor = Hilos::$db->users->actions->createWithName('Survivor');
            $loser = Hilos::$db->users->actions->createWithName('Loser');
            Database::sqlRun(
                'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
                [(int)$loser->id, (int)$survivor->id, '2026-10-08 12:00:00'],
            );
            Hilos::$rt->connections->actions->register('merged-rename-ak', $loser->id);
            Hilos::$rt->hilosModerationChecks->actions->open(
                'merged-rename-ak', $loser->id, ModerationKindKey::PROFILE_NAME, text: 'Alice',
            );
            Hilos::initSignalRouter(new ChatSignalRouter());

            $agentSignalData = new AgentSignalData(new ModerationVerdictSignalData(
                acceptKey: 'merged-rename-ak',
                kind: ModerationKindKey::PROFILE_NAME,
                requestId: Hilos::$rt->hilosModerationChecks->find('merged-rename-ak', ModerationKindKey::PROFILE_NAME)->requestId,
                allow: true,
                reason: 'ok',
            ));
            ExecutionContext::setCurrentAcceptKey('merged-rename-ak');
            try {
                $this->usersLibrary()->onSignalAgent(
                    $agentSignalData,
                    '',
                    HilosSignalConstants::HILOS_MODERATION_VERDICT,
                );
                $carried = $this->deliverPersonAgentFrames();
            } finally {
                ExecutionContext::setCurrentAcceptKey(null);
            }

            $this->assertSame(0, $carried);
            $this->assertSame('Loser', Hilos::$db->users[$loser->id]->name);
            $errorSignal = $this->takeQueuedWebSocketSignal(SignalConstants::ACTION_ERROR);
            $this->assertNotNull($errorSignal);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $errorSignal->data);
            $this->assertSame(HilosSignalConstants::PROFILE_NAME_SET, $errorSignal->data->action);
            $this->assertSame(
                'Failed to update user: ' . AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE,
                $errorSignal->data->reason,
            );
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
        }
    }

    public function testRejectedRenameModerationResultPreservesNameAndReportsActionError(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            $oldName = $user->name;
            Hilos::$rt->connections->actions->register('rename-reject-ak', $user->id);
            Hilos::$rt->hilosModerationChecks->actions->open(
                'rename-reject-ak', $user->id, ModerationKindKey::PROFILE_NAME, text: 'BlockedName',
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchRenameModerationVerdict(
                new ModerationVerdictSignalData(
                    acceptKey: 'rename-reject-ak',
                    kind: ModerationKindKey::PROFILE_NAME,
                    requestId: Hilos::$rt->hilosModerationChecks->find('rename-reject-ak', ModerationKindKey::PROFILE_NAME)->requestId,
                    allow: false,
                    reason: 'spam',
                ),
            );

            $this->assertSame($oldName, Hilos::$db->users[$user->id]->name);
            $this->assertNull(Hilos::$rt->hilosModerationChecks->find('rename-reject-ak', ModerationKindKey::PROFILE_NAME));

            // The reject is routed back through the framework action_error
            // contract (PageSignalRouter → default onActionException), not a
            // bespoke fail signal.
            $errorSignal = $this->takeQueuedWebSocketSignal(SignalConstants::ACTION_ERROR);
            $this->assertNotNull($errorSignal);
            $this->assertSame('rename-reject-ak', $errorSignal->targetAcceptKey);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $errorSignal->data);
            $this->assertSame(HilosSignalConstants::PROFILE_NAME_SET, $errorSignal->data->action);
            $this->assertSame('This name was not accepted because it appears to be spam.', $errorSignal->data->reason);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    /**
     * Hands the moderator's verdict to the library that owns the account (HIL-771).
     *
     * The verdict comes back to the users library the profile submit asked from, and an approved
     * name goes one hop further: the person's agent writes it and answers the library, which then
     * writes the feed line (HIL-1404). Both hops are carried here, as the workers would carry them.
     *
     * @param ModerationVerdictSignalData $result Verdict as the moderator sends it
     * @throws HilosException When the verdict cannot be applied
     */
    private function dispatchRenameModerationVerdict(ModerationVerdictSignalData $result): void
    {
        $agentSignalData = new AgentSignalData($result);
        ExecutionContext::setCurrentAcceptKey($agentSignalData->getAcceptKey());
        try {
            $this->usersLibrary()->onSignalAgent(
                $agentSignalData,
                '',
                HilosSignalConstants::HILOS_MODERATION_VERDICT,
            );
            $this->deliverPersonAgentFrames();
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    private function takeQueuedWebSocketSignal(string $signalName): ?WebSocketSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== $signalName) {
                continue;
            }

            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);

            return $signal->data;
        }

        return null;
    }

    private function assertUserRenamedEventExists(int $userId, string $oldName, string $newName): void
    {
        foreach (Hilos::$db->events as $event) {
            if (
                $event->type === ChatEventType::USER_RENAMED->value
                && $event->userRename?->userId === $userId
                && $event->userRename->renamedByUserId === $userId
                && $event->userRename->oldName === $oldName
                && $event->userRename->newName === $newName
            ) {
                return;
            }
        }

        $this->fail("Expected user_renamed event for '{$newName}' to exist.");
    }
}
