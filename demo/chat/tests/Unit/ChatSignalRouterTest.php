<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Hilos\Constants\HilosAgentType;
use Demo\Chat\Constants\GroupConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Constants\HilosSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Notification\ChatDeliveryChannelRegistry;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Notification\NotificationGroup;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for chat signal routing ownership.
 */
final class ChatSignalRouterTest extends TestCase
{
    public function testAdminLibraryActionsRouteToLibraryAgent(): void
    {
        $router = new ChatSignalRouter();

        foreach ([
            ChatSignalConstants::BOT_CREATE,
            ChatSignalConstants::BOT_UPDATE,
            ChatSignalConstants::BOT_DELETE,
        ] as $action) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::ACTION),
                new SignalName($action),
                new WebSocketActionSignalDTO('accept-key', $action),
            ));

            $this->assertEquals([
                new AgentDestination(AgentType::LIBRARY),
            ], $destinations);
        }
    }

    public function testPageOwnedActionsRouteToOwningPageAgent(): void
    {
        $router = new ChatSignalRouter();

        foreach (Hilos::getActionAgentRoutes() as $action => $agentType) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::ACTION),
                new SignalName($action),
                new WebSocketActionSignalDTO('accept-key', $action),
            ));

            $this->assertEquals([
                new AgentDestination($agentType),
            ], $destinations);
        }
    }

    public function testUnknownActionsDoNotUseWebSocketActionFallback(): void
    {
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName('unknown_action'),
            new WebSocketActionSignalDTO('accept-key', 'unknown_action'),
        ));

        $this->assertSame([], $destinations);
    }

    public function testAdminLibraryPagesSubscribeThroughLibraryAgent(): void
    {
        $router = new ChatSignalRouter();

        foreach ([PageConstants::ADMIN_BOTS] as $page) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                new SignalName($page),
                new WebSocketPageSubscribeSignalDTO('accept-key', $page),
            ));

            $this->assertEquals([
                new AgentDestination(AgentType::LIBRARY),
            ], $destinations);
        }
    }

    /**
     * The bot card is served by the library agent, with no instance index on the destination.
     */
    public function testBotCardSubscribesThroughLibraryAgentWithoutAnIndex(): void
    {
        $router = new ChatSignalRouter();
        $expected = [new AgentDestination(AgentType::LIBRARY)];

        foreach ([
            SignalTypeConstants::PAGE_SUBSCRIBE => new WebSocketPageSubscribeSignalDTO(
                'accept-key',
                PageConstants::BOT,
                ['id' => '7'],
            ),
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => new WebSocketPageUpdateSubscriptionSignalDTO(
                'accept-key',
                PageConstants::BOT,
                ['id' => '7'],
            ),
            SignalTypeConstants::PAGE_UNSUBSCRIBE => new WebSocketPageUnsubscribeSignalDTO('accept-key'),
        ] as $signalType => $data) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType($signalType),
                new SignalName(PageConstants::BOT),
                $data,
            ));

            $this->assertEquals($expected, $destinations);
            $this->assertNull($destinations[0]->agentIndex);
        }
    }

    public function testGroupSubscriptionsRouteFromTopology(): void
    {
        $router = new ChatSignalRouter();

        $destinations = $router->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::GROUP_SUBSCRIBE),
            new SignalName(GroupConstants::SESSION),
            new WebSocketGroupSubscribeSignalDTO('accept-key', GroupConstants::SESSION),
        ));

        $this->assertEquals([
            new AgentDestination(Hilos::getGroupRoutes()[GroupConstants::SESSION]),
        ], $destinations);
    }

    public function testUnregisteredGroupRoutesToNobody(): void
    {
        // The chat fallback that used to answer here is gone (HIL-721): it sent every
        // unrecognized group to the chat agent, notifications included, which the registry
        // names an owner for instead. A join nobody owns is now refused by the master with
        // a subscription_group_error rather than delivered to whoever happened to be default.
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::GROUP_SUBSCRIBE),
            new SignalName('unknown_group'),
            new WebSocketGroupSubscribeSignalDTO('accept-key', 'unknown_group'),
        ));

        $this->assertSame([], $destinations);
    }

    public function testParameterizedGroupNameRoutesToTheOwnerOfItsHead(): void
    {
        // The name on the wire carries the recipient, the registry is keyed by the name
        // without one, and the owner has to be found anyway: a name that resolves to nobody
        // would be dropped, and the address check that refuses it is in the worker.
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::GROUP_SUBSCRIBE),
            new SignalName(NotificationGroup::forUser(42)),
            new WebSocketGroupSubscribeSignalDTO('accept-key', NotificationGroup::forUser(42)),
        ));

        $this->assertEquals([
            new AgentDestination(AgentType::HILOS_NOTIFICATIONS_LIBRARY),
        ], $destinations);
    }

    public function testPageSubscriptionsRouteFromTopology(): void
    {
        $router = new ChatSignalRouter();

        foreach ([
            PageConstants::BOT,
            PageConstants::HILOS_I18N_LANGUAGES,
            HilosPageConstants::HILOS_I18N_LANGUAGE,
            HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES,
            HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES,
            HilosPageConstants::HILOS_I18N_COUNTRY,
            HilosPageConstants::HILOS_I18N_COUNTRY_NAMES,
        ] as $page) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                new SignalName($page),
                new WebSocketPageSubscribeSignalDTO('accept-key', $page),
            ));

            $this->assertEquals([
                new AgentDestination(Hilos::getPageRoutes()[$page]),
            ], $destinations);
        }
    }

    public function testInitialSystemSignalStartsLibraryAgent(): void
    {
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::INITIAL_AGENTS_START),
            new SystemSignalDTO(SignalConstants::INITIAL_AGENTS_START),
        ));

        $agentTypes = array_map(static fn (AgentDestination $destination): string => $destination->agentType, $destinations);

        $this->assertContains(AgentType::LIBRARY, $agentTypes);
    }

    public function testBotMessageRoutesToChatAgent(): void
    {
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::BOT_MESSAGE),
            new AgentSignalData(new BotMessageSignalData(botId: 7, message: 'hello')),
        ));

        $this->assertEquals([
            new AgentDestination(AgentType::CHAT),
        ], $destinations);
    }

    public function testChatVerdictsRouteByKindAndTheRegistryAnswerBelongsToTheAgent(): void
    {
        foreach ([ChatModerationKindKey::MESSAGE, ChatModerationKindKey::MESSAGE_PICTURE] as $kind) {
            $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::AGENT),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(HilosSignalConstants::HILOS_MODERATION_VERDICT),
                new AgentSignalData(new ModerationVerdictSignalData('accept-key', $kind, 'attempt', true, 'ok')),
            ));
            $this->assertEquals([new AgentDestination(AgentType::CHAT)], $destinations);
        }
        $this->assertEquals([new AgentDestination(AgentType::CHAT)], new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::ATTACHMENTS_PUBLISHED),
            new AgentSignalData(new FilesPublishedSignalData('accept-key', ['u1'], [1], null)),
        )));
        $this->assertSame(AgentType::CHAT, Hilos::getAgentSignalRoutes()[ChatSignalConstants::ATTACHMENTS_PUBLISHED]);
        $this->assertArrayNotHasKey(ChatSignalConstants::ATTACHMENTS_PUBLISHED, Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]);
        foreach (['moderation_result', 'subscription_page_moderator'] as $name) {
            $this->assertSame([], new ChatSignalRouter()->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::AGENT),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName($name),
                new AgentSignalData(new SignalData()),
            )));
        }
        $this->assertArrayNotHasKey('moderator', Hilos::getPageRoutes());
        $this->assertArrayNotHasKey(HilosSignalConstants::HILOS_MODERATION_VERDICT, Hilos::getAgentSignalRoutes());
    }

    public function testNameModerationVerdictRoutesByKindToTheUsersLibrary(): void
    {
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_MODERATION_VERDICT),
            new AgentSignalData(new ModerationVerdictSignalData(
                acceptKey: 'accept-key',
                kind: ModerationKindKey::PROFILE_NAME,
                requestId: 'name-attempt',
                allow: true,
                reason: '',
            )),
        ));

        $this->assertEquals([
            new AgentDestination(HilosAgentType::HILOS_USERS_LIBRARY),
        ], $destinations);
    }

    public function testTheOldRenameModerationSignalHasNoRoute(): void
    {
        self::assertSame([], new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('rename_moderation_result'),
            new AgentSignalData(new SignalData()),
        )));
        self::assertArrayNotHasKey('rename', Hilos::getAgentActionRoutes());
    }

    /**
     * A file's chunks go to the framework uploads agent whatever page is open (HIL-144).
     */
    public function testBinaryFramesRouteToTheUploadsAgent(): void
    {
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName(SignalTypeConstants::FRAME_BINARY),
            new WebSocketFrameBinarySignalDTO('accept-key', 'payload'),
        ));

        $this->assertEquals([
            new AgentDestination(HilosAgentType::HILOS_UPLOADS),
        ], $destinations);
    }

    public function testBotAgentStartRoutesToBotAgentWithExtractedIndex(): void
    {
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::BOT_AGENT_START),
            new AgentSignalData(new BotAgentSignalData(botId: 42)),
        ));

        $this->assertEquals([
            new AgentDestination(AgentType::BOT, '42'),
        ], $destinations);
    }

    /**
     * The delivery seam on the real chat topology (HIL-567).
     *
     * The framework test for this runs on a fixture facade, which proves the rule but not
     * that the rule survives contact with a project: chat is the demo that registers all
     * three delivery channels, so it is the one place where the dispatcher's signal — queued
     * from a worker, named by the channel descriptor — has to find a live agent.
     */
    public function testChannelDeliverSignalsFromAWorkerReachTheirChannelAgents(): void
    {
        $router = new ChatSignalRouter();

        foreach (ChatDeliveryChannelRegistry::all() as $channel => $descriptor) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WORKER),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName($descriptor->deliverSignalName()),
                new AgentSignalData(new NotificationDeliverSignalData(
                    notificationId: 1,
                    channel: $channel,
                    shardKey: 4,
                )),
            ));

            $this->assertNotSame([], $destinations, "Channel {$channel} lost its delivery route");
        }
    }

    public function testBotAgentStartWithZeroBotIdProducesNoDestination(): void
    {
        $destinations = new ChatSignalRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::BOT_AGENT_START),
            new AgentSignalData(new BotAgentSignalData(botId: 0)),
        ));

        $this->assertSame([], $destinations);
    }
}
