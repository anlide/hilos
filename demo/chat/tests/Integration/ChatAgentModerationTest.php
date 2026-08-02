<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for ChatAgent shutdown and moderation result cleanup.
 */
final class ChatAgentModerationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testOnStopClearsConnectionsAndUserStates(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('stop-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
            Hilos::$rt->connections['stop-ak']?->actions->startOutboundModeration(
                'pending moderation',
            );

            $agent = new ChatAgent();
            $agent->onStop();

            $this->assertSame(0, count(Hilos::$rt->connections));
            $this->assertSame(0, count(Hilos::$rt->userStates));
            $this->assertSame(0, count(Hilos::$rt->attachmentDrafts));
            $this->assertEventTypeExists(ChatEventType::CHAT_STOPPED);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testStaleTextModerationResultThrowsAgentExceptionAndDoesNotPublishMessage(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->userStates->actions->ensure($user->id);

            Hilos::initSignalRouter(new ChatSignalRouter());
            try {
                $this->dispatchTextModerationSignalToMainPage(
                    new ChatAgent(),
                    new ModerationResultSignalData(
                        acceptKey: 'closed-ak',
                        userId: $user->id,
                        message: 'message after disconnect',
                        allow: true,
                        reason: 'ok',
                    ),
                );
                $this->fail('Expected stale moderation result to throw agent exception.');
            } catch (AgentException $e) {
                $this->assertSame('Moderation result connection is stale', $e->getMessage());
            }

            $this->assertNoMessageEvents();
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testApprovedTextModerationResultPublishesMessageAndClearsPendingState(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('live-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
            Hilos::$rt->connections['live-ak']?->actions->startOutboundModeration(
                'pending moderation',
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchTextModerationSignalToMainPage(
                new ChatAgent(),
                new ModerationResultSignalData(
                    acceptKey: 'live-ak',
                    userId: $user->id,
                    message: 'approved message',
                    allow: true,
                    reason: 'ok',
                ),
            );

            $this->assertSame(
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
                Hilos::$rt->connections['live-ak']?->outboundModerationPhase,
            );
            $this->assertMessageEventExists('approved message');
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testRejectedTextModerationResultUsesActionExceptionAndPreservesRetryState(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('reject-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
            Hilos::$rt->connections['reject-ak']?->actions->startOutboundModeration(
                'blocked message',
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchTextModerationSignalToMainPage(
                new ChatAgent(),
                new ModerationResultSignalData(
                    acceptKey: 'reject-ak',
                    userId: $user->id,
                    message: 'blocked message',
                    allow: false,
                    reason: 'policy',
                ),
            );

            $this->assertSame(
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED,
                Hilos::$rt->connections['reject-ak']?->outboundModerationPhase,
            );
            $this->assertSame('policy', Hilos::$rt->connections['reject-ak']?->outboundModerationReason);

            $actionErrorSignal = $this->takeQueuedActionErrorSignal();
            $this->assertNotNull($actionErrorSignal);
            $this->assertSame('reject-ak', $actionErrorSignal->targetAcceptKey);
            $this->assertInstanceOf(PageActionErrorSignalData::class, $actionErrorSignal->data);
            $this->assertSame(ChatSignalConstants::MESSAGE, $actionErrorSignal->data->action);
            $this->assertSame('policy', $actionErrorSignal->data->reason);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testMainPageRejectsMessageInsideRuntimeRateLimit(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('rate-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();

            $this->expectException(ValidationException::class);

            ExecutionContext::setCurrentAcceptKey('rate-ak');
            new MainPage(new ChatAgent())->onAction(
                'rate-ak',
                ChatSignalConstants::MESSAGE,
                new MessageActionDTO('too soon'),
            );
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testBotMessageSignalPublishesMessage(): void
    {
        Hilos::$db->events->actions->deleteAll();

        try {
            $bot = Hilos::$db->bots->actions->create('Signal Bot', active: true);

            new ChatAgent()->onSignalAgent(
                new AgentSignalData(new BotMessageSignalData(botId: (int) $bot->id, message: 'bot says hi')),
                '',
                ChatSignalConstants::BOT_MESSAGE,
            );

            $this->assertBotMessageEventExists((int) $bot->id, 'bot says hi');
        } finally {
            Hilos::$db->events->actions->deleteAll();
        }
    }

    private function assertEventTypeExists(ChatEventType $type): void
    {
        foreach (Hilos::$db->events as $event) {
            if ($event->type === $type->value) {
                return;
            }
        }

        $this->fail("Expected event type {$type->value} to exist.");
    }

    private function assertNoMessageEvents(): void
    {
        $messageEvents = 0;
        foreach (Hilos::$db->events as $event) {
            if ($event->type === ChatEventType::MESSAGE_SENT->value) {
                $messageEvents++;
            }
        }

        $this->assertSame(0, $messageEvents);
    }

    private function assertMessageEventExists(string $message): void
    {
        foreach (Hilos::$db->events as $event) {
            if (
                $event->type === ChatEventType::MESSAGE_SENT->value
                && $event->eventMessage?->message === $message
            ) {
                return;
            }
        }

        $this->fail("Expected message event '{$message}' to exist.");
    }

    private function assertBotMessageEventExists(int $botId, string $message): void
    {
        foreach (Hilos::$db->events as $event) {
            if (
                $event->type === ChatEventType::MESSAGE_SENT->value
                && $event->eventMessage?->authorBotId === $botId
                && $event->eventMessage?->message === $message
            ) {
                $this->assertSame($botId, $event->eventMessage?->authorBotId);
                $this->assertSame($message, $event->eventMessage?->message);
                return;
            }
        }

        $this->fail("Expected bot message event '{$message}' to exist.");
    }

    private function takeQueuedActionErrorSignal(): ?WebSocketSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalConstants::ACTION_ERROR) {
                continue;
            }

            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);

            return $signal->data;
        }

        return null;
    }

    private function dispatchTextModerationSignalToMainPage(ChatAgent $agent, ModerationResultSignalData $result): void
    {
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(),
            new SignalRouteConfig([
                SignalTypeConstants::AGENT_SIGNAL => [
                    ChatSignalConstants::MODERATION_RESULT => MainPage::PAGE,
                ],
            ]),
        );

        $agentSignalData = new AgentSignalData($result);
        ExecutionContext::setCurrentAcceptKey($agentSignalData->getAcceptKey());
        try {
            $router->dispatchAgentSignal(
                $agentSignalData,
                '',
                ChatSignalConstants::MODERATION_RESULT,
            );
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }
}
