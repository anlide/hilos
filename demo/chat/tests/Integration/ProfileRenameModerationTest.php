<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Auth\ChatStepUpOperationKey;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
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
                ChatStepUpOperationKey::CHANGE_NAME,
                date('Y-m-d H:i:s', time() + 3600),
            );

            ExecutionContext::setCurrentAcceptKey('rename-start-ak');
            $this->usersLibrary()->onAgentAction(
                'rename-start-ak',
                ChatSignalConstants::RENAME,
                new RenameActionDTO('Alice'),
            );

            $this->assertSame($oldName, Hilos::$db->users[$user->id]?->name);
            $this->assertSame(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_CHECKING,
                Hilos::$rt->connections['rename-start-ak']?->renameModerationPhase,
            );
            $this->assertSame('Alice', Hilos::$rt->connections['rename-start-ak']?->renameModerationName);
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
            Hilos::$rt->connections['rename-approve-ak']?->actions->startRenameModeration('Alice');

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchRenameModerationVerdict(
                new RenameModerationResultSignalData(
                    acceptKey: 'rename-approve-ak',
                    userId: $user->id,
                    newName: 'Alice',
                    allow: true,
                    reason: 'ok',
                ),
            );

            $this->assertSame('Alice', Hilos::$db->users[$user->id]?->name);
            $this->assertSame(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_NONE,
                Hilos::$rt->connections['rename-approve-ak']?->renameModerationPhase,
            );
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
            Hilos::$rt->connections['merged-rename-ak']?->actions->startRenameModeration('Alice');
            Hilos::initSignalRouter(new ChatSignalRouter());

            $agentSignalData = new AgentSignalData(new RenameModerationResultSignalData(
                acceptKey: 'merged-rename-ak',
                userId: (int)$loser->id,
                newName: 'Alice',
                allow: true,
                reason: 'ok',
            ));
            ExecutionContext::setCurrentAcceptKey('merged-rename-ak');
            try {
                $this->usersLibrary()->onSignalAgent(
                    $agentSignalData,
                    '',
                    ChatSignalConstants::RENAME_MODERATION_RESULT,
                );
                $carried = $this->deliverPersonAgentFrames();
            } finally {
                ExecutionContext::setCurrentAcceptKey(null);
            }

            $this->assertSame(0, $carried);
            $this->assertSame('Loser', Hilos::$db->users[$loser->id]?->name);
            $errorSignal = $this->takeQueuedWebSocketSignal(SignalConstants::ACTION_ERROR);
            $this->assertNotNull($errorSignal);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $errorSignal->data);
            $this->assertSame(ChatSignalConstants::RENAME, $errorSignal->data->action);
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
            Hilos::$rt->connections['rename-reject-ak']?->actions->startRenameModeration('BlockedName');

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchRenameModerationVerdict(
                new RenameModerationResultSignalData(
                    acceptKey: 'rename-reject-ak',
                    userId: $user->id,
                    newName: 'BlockedName',
                    allow: false,
                    reason: 'policy',
                ),
            );

            $this->assertSame($oldName, Hilos::$db->users[$user->id]?->name);
            $this->assertSame(
                ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_REJECTED,
                Hilos::$rt->connections['rename-reject-ak']?->renameModerationPhase,
            );
            $this->assertSame('policy', Hilos::$rt->connections['rename-reject-ak']?->renameModerationReason);

            // The reject is routed back through the framework action_error
            // contract (PageSignalRouter → default onActionException), not a
            // bespoke fail signal.
            $errorSignal = $this->takeQueuedWebSocketSignal(SignalConstants::ACTION_ERROR);
            $this->assertNotNull($errorSignal);
            $this->assertSame('rename-reject-ak', $errorSignal->targetAcceptKey);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $errorSignal->data);
            $this->assertSame(ChatSignalConstants::RENAME, $errorSignal->data->action);
            $this->assertSame('This name was not accepted.', $errorSignal->data->reason);
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
     * @param RenameModerationResultSignalData $result Verdict as the moderator sends it
     * @throws HilosException When the verdict cannot be applied
     */
    private function dispatchRenameModerationVerdict(RenameModerationResultSignalData $result): void
    {
        $agentSignalData = new AgentSignalData($result);
        ExecutionContext::setCurrentAcceptKey($agentSignalData->getAcceptKey());
        try {
            $this->usersLibrary()->onSignalAgent(
                $agentSignalData,
                '',
                ChatSignalConstants::RENAME_MODERATION_RESULT,
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
