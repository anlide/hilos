<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\GroupConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
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
            ChatSignalConstants::MODERATOR_PIECE_CREATE,
            ChatSignalConstants::MODERATOR_PIECE_UPDATE,
            ChatSignalConstants::MODERATOR_PIECE_DELETE,
        ] as $action) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::ACTION),
                new SignalName($action),
                new WebSocketActionSignalDTO('accept-key', $action),
            ));

            $this->assertSame([
                [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::LIBRARY, AgentConstants::FIELD_AGENT_INDEX => null],
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

            $this->assertSame([
                [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => $agentType, AgentConstants::FIELD_AGENT_INDEX => null],
            ], $destinations);
        }
    }

    public function testAttachmentDraftDeleteRouteIsDeclaredByMainPage(): void
    {
        $this->assertSame(
            PageConstants::MAIN,
            Hilos::getPageActionRoutes()[ChatSignalConstants::ATTACHMENT_DRAFT_DELETE] ?? null,
        );
        $this->assertSame(
            AgentType::CHAT,
            Hilos::getActionAgentRoutes()[ChatSignalConstants::ATTACHMENT_DRAFT_DELETE] ?? null,
        );
    }

    public function testUnknownActionsDoNotUseWebSocketActionFallback(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
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

        foreach ([PageConstants::ADMIN_BOTS, PageConstants::ADMIN_MODERATOR] as $page) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                new SignalName($page),
                new WebSocketPageSubscribeSignalDTO('accept-key', $page),
            ));

            $this->assertSame([
                [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::LIBRARY, AgentConstants::FIELD_AGENT_INDEX => null],
            ], $destinations);
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

        $this->assertSame([
            [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => Hilos::getGroupRoutes()[GroupConstants::SESSION], AgentConstants::FIELD_AGENT_INDEX => null],
        ], $destinations);
    }

    public function testUnregisteredGroupUsesChatFallback(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::GROUP_SUBSCRIBE),
            new SignalName('unknown_group'),
            new WebSocketGroupSubscribeSignalDTO('accept-key', 'unknown_group'),
        ));

        $this->assertSame([
            [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::CHAT, AgentConstants::FIELD_AGENT_INDEX => null],
        ], $destinations);
    }

    public function testPageSubscriptionsRouteFromTopology(): void
    {
        $router = new ChatSignalRouter();

        foreach ([PageConstants::BOT, PageConstants::HILOS_I18N_LANGUAGES] as $page) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                new SignalName($page),
                new WebSocketPageSubscribeSignalDTO('accept-key', $page),
            ));

            $this->assertSame([
                [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => Hilos::getPageRoutes()[$page], AgentConstants::FIELD_AGENT_INDEX => null],
            ], $destinations);
        }
    }

    public function testInitialSystemSignalStartsLibraryAgent(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::INITIAL_AGENTS_START),
            new SystemSignalDTO(SignalConstants::INITIAL_AGENTS_START),
        ));

        $agentTypes = array_map(static fn (array $destination): ?string => $destination[AgentConstants::FIELD_AGENT_TYPE] ?? null, $destinations);

        $this->assertContains(AgentType::LIBRARY, $agentTypes);
    }

    public function testBotMessageRoutesToChatAgent(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::BOT_MESSAGE),
            new AgentSignalData(new BotMessageSignalData(botId: 7, message: 'hello')),
        ));

        $this->assertSame([
            [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::CHAT, AgentConstants::FIELD_AGENT_INDEX => null],
        ], $destinations);
    }

    public function testModerationResultRoutesToChatAgentThroughPageSignalOwnership(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::MODERATION_RESULT),
            new AgentSignalData(new ModerationResultSignalData(
                acceptKey: 'accept-key',
                userId: 7,
                message: 'hello',
                allow: true,
                reason: '',
            )),
        ));

        $this->assertSame([
            [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::CHAT, AgentConstants::FIELD_AGENT_INDEX => null],
        ], $destinations);
    }

    public function testRenameModerationResultRoutesToChatAgentThroughPageSignalOwnership(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::RENAME_MODERATION_RESULT),
            new AgentSignalData(new RenameModerationResultSignalData(
                acceptKey: 'accept-key',
                userId: 7,
                newName: 'new-name',
                allow: true,
                reason: '',
            )),
        ));

        $this->assertSame([
            [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::CHAT, AgentConstants::FIELD_AGENT_INDEX => null],
        ], $destinations);
    }

    public function testBinaryFramesRouteToChatAgentThroughPageSignalOwnership(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName(SignalName::EMPTY),
            new WebSocketFrameBinarySignalDTO('accept-key', 'payload'),
        ));

        $this->assertSame([
            [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::CHAT, AgentConstants::FIELD_AGENT_INDEX => null],
        ], $destinations);
    }

    public function testBotAgentStartRoutesToBotAgentWithExtractedIndex(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::BOT_AGENT_START),
            new AgentSignalData(new BotAgentSignalData(botId: 42)),
        ));

        $this->assertSame([
            [SignalPayloadConstants::FIELD_TYPE => AgentConstants::DESTINATION_TYPE_AGENT, AgentConstants::FIELD_AGENT_TYPE => AgentType::BOT, AgentConstants::FIELD_AGENT_INDEX => '42'],
        ], $destinations);
    }

    public function testBotAgentStartWithZeroBotIdProducesNoDestination(): void
    {
        $destinations = (new ChatSignalRouter())->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(ChatSignalConstants::BOT_AGENT_START),
            new AgentSignalData(new BotAgentSignalData(botId: 0)),
        ));

        $this->assertSame([], $destinations);
    }
}
