<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Pages\DTO\Profile\RenameActionDTO;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
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
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $oldName = $user->name;
            Hilos::$rt->connections->actions->register('rename-start-ak', $user->id);

            Hilos::initSignalRouter(new ChatSignalRouter());
            ExecutionContext::setCurrentAcceptKey('rename-start-ak');
            (new ProfilePage(new ChatAgent()))->onAction(
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

    public function testApprovedRenameModerationResultRenamesUserAndSendsSuccess(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $oldName = $user->name;
            Hilos::$rt->connections->actions->register('rename-approve-ak', $user->id);
            Hilos::$rt->connections['rename-approve-ak']?->actions->startRenameModeration('Alice');

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchRenameModerationSignalToProfilePage(
                new ChatAgent(),
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

            $successSignal = $this->takeQueuedWebSocketSignal(ChatSignalConstants::RENAME_SUCCESS);
            $this->assertNotNull($successSignal);
            $this->assertSame('rename-approve-ak', $successSignal->targetAcceptKey);
            $this->assertInstanceOf(ActionSuccessSignalData::class, $successSignal->data);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testRejectedRenameModerationResultPreservesNameAndSendsFail(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            $oldName = $user->name;
            Hilos::$rt->connections->actions->register('rename-reject-ak', $user->id);
            Hilos::$rt->connections['rename-reject-ak']?->actions->startRenameModeration('BlockedName');

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchRenameModerationSignalToProfilePage(
                new ChatAgent(),
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

            $failSignal = $this->takeQueuedWebSocketSignal(ChatSignalConstants::RENAME_FAIL);
            $this->assertNotNull($failSignal);
            $this->assertSame('rename-reject-ak', $failSignal->targetAcceptKey);
            $this->assertInstanceOf(ActionFailSignalData::class, $failSignal->data);
            $this->assertSame('policy', $failSignal->data->reason);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    private function dispatchRenameModerationSignalToProfilePage(
        ChatAgent $agent,
        RenameModerationResultSignalData $result,
    ): void {
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(),
            new SignalRouteConfig([
                SignalTypeConstants::AGENT_SIGNAL => [
                    ChatSignalConstants::RENAME_MODERATION_RESULT => ProfilePage::PAGE,
                ],
            ]),
        );

        $agentSignalData = new AgentSignalData($result);
        ExecutionContext::setCurrentAcceptKey($agentSignalData->getAcceptKey());
        try {
            $router->dispatchAgentSignal(
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
