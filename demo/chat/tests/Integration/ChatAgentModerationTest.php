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
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

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
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('stop-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
            Hilos::$rt->connections['stop-ak']?->actions->startOutboundModeration(
                'request-stop',
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

    public function testStaleTextModerationResultDoesNotPublishMessage(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->userStates->actions->ensure($user->id);

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchTextModerationSignalToMainPage(
                new ChatAgent(),
                new ModerationResultSignalData(
                    requestId: 'request-closed',
                    acceptKey: 'closed-ak',
                    userId: $user->id,
                    message: 'message after disconnect',
                    allow: true,
                    reason: 'ok',
                ),
            );

            $this->assertNoMessageEvents();
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testApprovedTextModerationResultPublishesMessageAndClearsPendingState(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('live-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
            Hilos::$rt->connections['live-ak']?->actions->startOutboundModeration(
                'request-live',
                'pending moderation',
            );

            Hilos::initSignalRouter(new ChatSignalRouter());
            $this->dispatchTextModerationSignalToMainPage(
                new ChatAgent(),
                new ModerationResultSignalData(
                    requestId: 'request-live',
                    acceptKey: 'live-ak',
                    userId: $user->id,
                    message: 'approved message',
                    allow: true,
                    reason: 'ok',
                ),
            );

            $this->assertSame(
                Connection::OUTBOUND_MODERATION_PHASE_NONE,
                Hilos::$rt->connections['live-ak']?->outboundModerationPhase,
            );
            $this->assertMessageEventExists('approved message');
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
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('rate-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();

            $this->expectException(ValidationException::class);

            ExecutionContext::run(
                new ExecutionFrame(acceptKey: 'rate-ak'),
                static fn() => (new MainPage(new ChatAgent()))->onAction(
                    'rate-ak',
                    ChatSignalConstants::MESSAGE,
                    new MessageActionDTO('too soon'),
                ),
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
            $data = is_string($event->data) ? json_decode($event->data, true) : null;
            if (
                $event->type === ChatEventType::MESSAGE_SENT->value
                && is_array($data)
                && ($data['message'] ?? null) === $message
            ) {
                return;
            }
        }

        $this->fail("Expected message event '{$message}' to exist.");
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
