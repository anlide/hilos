<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for user-initiated rename moderation.
 */
final class ProfileRenameModerationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testRenameActionStartsModerationWithoutChangingUserName(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            $oldName = $user->name;
            Hilos::$rt->connections->actions->register('rename-start-ak', $user->id);

            Hilos::initSignalRouter(new ChatSignalRouter());
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
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
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

    public function testRejectedRenameModerationResultPreservesNameAndReportsActionError(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
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
            $this->assertSame('policy', $errorSignal->data->reason);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    /**
     * Hands the moderator's verdict to the library that owns the account (HIL-771).
     *
     * The whole round trip is one agent's now: the profile submit asks from the users library
     * and the answer comes back to it, because applying a verdict means writing the row - which
     * the page it used to arrive on holds no claim over.
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
                && $event->eventUserRename?->targetUserId === $userId
                && $event->eventUserRename?->oldName === $oldName
                && $event->eventUserRename?->newName === $newName
            ) {
                return;
            }
        }

        $this->fail("Expected user_renamed event for '{$newName}' to exist.");
    }
}
