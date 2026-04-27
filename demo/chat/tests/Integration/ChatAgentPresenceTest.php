<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for ChatAgent runtime presence handling.
 */
final class ChatAgentPresenceTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testHandshakeAndCloseUpdatePresenceWithoutHistoryEvents(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        $sessionToken = bin2hex(random_bytes(16));
        $user = Hilos::$db->users->actions->register($sessionToken);
        $agent = new ChatAgent();

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'presence-listener-ak',
            PageConstants::MAIN,
            [],
        ));

        try {
            $eventCountBeforeHandshake = count(Hilos::$db->events);

            $agent->onSignalHandshake(
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'presence-ak-1',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: [HttpHeaders::SESSION_TOKEN => $sessionToken],
                ),
                '',
                '',
            );

            $this->assertSame($eventCountBeforeHandshake, count(Hilos::$db->events));
            $this->assertSame(1, count(Hilos::$rt->connections->forUser($user->id)));
            $this->assertNoPresenceEventsInHistory();
            $this->assertContains(ChatSignalConstants::USER_PRESENCE_UPDATE, $this->drainQueuedSignalNames());

            $eventCountBeforeClose = count(Hilos::$db->events);
            Hilos::initSignalRouter(new ChatSignalRouter());
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'presence-listener-ak',
                PageConstants::MAIN,
                [],
            ));

            $agent->onSignalConnectionClose(new WebSocketCloseSignalDTO('presence-ak-1'), '', '');

            $this->assertSame($eventCountBeforeClose, count(Hilos::$db->events));
            $this->assertSame(0, count(Hilos::$rt->connections->forUser($user->id)));
            $this->assertNoPresenceEventsInHistory();
            $this->assertContains(ChatSignalConstants::USER_PRESENCE_UPDATE, $this->drainQueuedSignalNames());
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * @return list<string>
     */
    private function drainQueuedSignalNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }

    private function assertNoPresenceEventsInHistory(): void
    {
        foreach (Hilos::$db->events as $event) {
            $this->assertNotContains($event->type, ['user_online', 'user_offline']);
        }
    }
}
