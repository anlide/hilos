<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\ChatPageFactory;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for ChatAgent shutdown and moderation result cleanup.
 */
final class ChatAgentModerationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testOnStopClearsConnectionsAndUserStates(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(bin2hex(random_bytes(16)));
            Hilos::$rt->connections->actions->register('stop-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->userStates->actions->setTextModerationMessage($user->id, 'pending moderation');

            $agent = new ChatAgent();
            $agent->onStop();

            $this->assertSame(0, count(Hilos::$rt->connections));
            $this->assertSame(0, count(Hilos::$rt->userStates));
            $this->assertEventTypeExists(ChatEventType::CHAT_STOPPED);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testStaleTextModerationResultClearsPendingStateWithoutPublishingMessage(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(bin2hex(random_bytes(16)));
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->userStates->actions->setTextModerationMessage($user->id, 'pending moderation');

            Hilos::initSignalRouter(new ChatSignalRouter());
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

            $this->assertSame('', Hilos::$rt->userStates[$user->id]?->moderationMessage);
            $this->assertNoMessageEvents();
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testApprovedTextModerationResultRecordsRuntimeRateLimit(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(bin2hex(random_bytes(16)));
            Hilos::$rt->connections->actions->register('live-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id);
            Hilos::$rt->userStates->actions->setTextModerationMessage($user->id, 'pending moderation');

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

            $this->assertGreaterThan(0.0, Hilos::$rt->userStates[$user->id]?->lastMessageSentAt);
            $this->assertFalse(Hilos::$rt->userStates->actions->canSendMessage($user->id));
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testMainPageRejectsMessageInsideRuntimeRateLimit(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(bin2hex(random_bytes(16)));
            Hilos::$rt->connections->actions->register('rate-ak', $user->id);
            Hilos::$rt->userStates->actions->recordMessageSent($user->id);

            $this->expectException(ValidationException::class);

            (new MainPage(new ChatAgent()))->onAction(
                'rate-ak',
                ChatSignalConstants::MESSAGE,
                new MessageActionDTO('too soon'),
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
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
        foreach (Hilos::$db->events as $event) {
            $this->assertNotSame(ChatEventType::MESSAGE_SENT->value, $event->type);
        }
    }

    private function dispatchTextModerationSignalToMainPage(ChatAgent $agent, ModerationResultSignalData $result): void
    {
        $router = new PageSignalRouter(
            new ChatPageFactory($agent),
            new ActionRouteConfig(),
            [
                SignalTypeConstants::AGENT_SIGNAL => [
                    ChatSignalConstants::MODERATION_RESULT => MainPage::PAGE,
                ],
            ],
        );

        $router->dispatchAgentSignal(
            new AgentSignalData($result),
            '',
            ChatSignalConstants::MODERATION_RESULT,
        );
    }
}
