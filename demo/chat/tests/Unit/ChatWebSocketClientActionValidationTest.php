<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Closure;
use Demo\Chat\Core\Socket\Client\ChatWebSocketClient;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for topology-driven WebSocket action validation.
 */
final class ChatWebSocketClientActionValidationTest extends TestCase
{
    public function testDeclaredPageActionsPassWebSocketValidation(): void
    {
        $client = $this->clientWithoutSocket();
        $validate = $this->actionValidator();

        foreach (array_keys(Hilos::getPageActionRoutes()) as $action) {
            $validate($client, $action);
        }

        $this->addToAssertionCount(count(Hilos::getPageActionRoutes()));
    }

    public function testUnknownActionFailsWebSocketValidation(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        $this->expectExceptionMessage('Unknown websocket action type: unknown_action');

        ($this->actionValidator())($this->clientWithoutSocket(), 'unknown_action');
    }

    /**
     * Creates a client test double without initializing socket or env state.
     *
     * @return ChatWebSocketClient WebSocket client instance
     */
    private function clientWithoutSocket(): ChatWebSocketClient
    {
        return (new ReflectionClass(ChatWebSocketClient::class))->newInstanceWithoutConstructor();
    }

    /**
     * Returns a bound caller for the protected action validation hook.
     *
     * @return Closure(ChatWebSocketClient, string): void
     */
    private function actionValidator(): Closure
    {
        return Closure::bind(
            static function (ChatWebSocketClient $client, string $action): void {
                $client->onActionValidated($action);
            },
            null,
            ChatWebSocketClient::class,
        );
    }
}
