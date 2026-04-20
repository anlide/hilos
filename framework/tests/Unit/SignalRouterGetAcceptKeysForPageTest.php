<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SignalRouter::getAcceptKeysForPage}.
 */
final class SignalRouterGetAcceptKeysForPageTest extends TestCase
{
    public function testFiltersByPageAndParam(): void
    {
        $router = new SignalRouter();
        $router->subscribeToPage('hilos_user', new WebSocketPageSubscribeSignalDTO('a1', 'hilos_user', ['id' => '5']));
        $router->subscribeToPage('hilos_user', new WebSocketPageSubscribeSignalDTO('a2', 'hilos_user', ['id' => '5']));
        $router->subscribeToPage('hilos_user', new WebSocketPageSubscribeSignalDTO('b1', 'hilos_user', ['id' => '9']));
        $router->subscribeToPage('main', new WebSocketPageSubscribeSignalDTO('c1', 'main', []));

        $keys = $router->getAcceptKeysForPage('hilos_user', 'id', '5');
        sort($keys);
        $this->assertSame(['a1', 'a2'], $keys);
    }

    public function testPageOnlyWithoutParamFilter(): void
    {
        $router = new SignalRouter();
        $router->subscribeToPage('user', new WebSocketPageSubscribeSignalDTO('u1', 'user', ['id' => '1']));
        $router->subscribeToPage('user', new WebSocketPageSubscribeSignalDTO('u2', 'user', ['id' => '2']));

        $keys = $router->getAcceptKeysForPage('user');
        sort($keys);
        $this->assertSame(['u1', 'u2'], $keys);
    }
}
