<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
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
                ['type' => 'agent', 'agentType' => AgentType::LIBRARY, 'agentIndex' => null],
            ], $destinations);
        }
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
                ['type' => 'agent', 'agentType' => AgentType::LIBRARY, 'agentIndex' => null],
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

        $agentTypes = array_map(static fn (array $destination): ?string => $destination['agentType'] ?? null, $destinations);

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
            ['type' => 'agent', 'agentType' => AgentType::CHAT, 'agentIndex' => null],
        ], $destinations);
    }
}
