<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SignalRouterPerInstancePageRoutingTest.php';

/** Node selection keeps original local subscription slots across binds and ordinary param updates. */
final class SignalRouterNodePageIndexTest extends TestCase
{
    public function testARequestedNodeIndexExcludesPlainPagesAndLaterRejoins(): void
    {
        $router = new PerInstanceTestRouter();
        $router->subscribeToPage(PerInstanceNodePage::PAGE,
            new WebSocketPageSubscribeSignalDTO('node', PerInstanceNodePage::PAGE, ['nodeId' => 'node-B']));
        $router->subscribeToPage(PerInstancePlainPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('plain', PerInstancePlainPage::PAGE, ['nodeId' => 'node-B']));
        $original = $router->nodePageCandidates('node-B');
        $router->unsubscribeFromPage(PerInstanceNodePage::PAGE, new WebSocketPageUnsubscribeSignalDTO('node'));
        $router->subscribeToPage(PerInstanceNodePage::PAGE,
            new WebSocketPageSubscribeSignalDTO('node', PerInstanceNodePage::PAGE, ['nodeId' => 'node-B']));
        foreach ($original as $slot) {
            self::assertNull($router->nodePageAcceptKey($slot, 'node-B'));
        }
        $slots = iterator_to_array($router->nodePageCandidates('node-B'), false);
        self::assertCount(1, $slots);
        self::assertSame('node', $router->nodePageAcceptKey($slots[0], 'node-B'));
        $router->unsubscribeFromAll('node');
        self::assertNull($router->nodePageAcceptKey($slots[0], 'node-B'));
        self::assertSame([], iterator_to_array($router->nodePageCandidates('node-B')));
    }

    public function testBindingAndAnOrdinaryUpdateKeepTheSlotButAnAddressChangeEndsIt(): void
    {
        $router = new PerInstanceTestRouter();
        $router->subscribeToPage(PerInstanceNodePage::PAGE,
            new WebSocketPageSubscribeSignalDTO('node', PerInstanceNodePage::PAGE, ['nodeId' => 'node-B']));
        $slots = iterator_to_array($router->nodePageCandidates('node-B'), false);
        $router->bindPageAgent('node', PerInstanceNodePage::PAGE, PerInstanceFallbackAgent::AGENT_TYPE, null);
        $router->updatePageSubscription(PerInstanceNodePage::PAGE,
            new WebSocketPageUpdateSubscriptionSignalDTO('node', PerInstanceNodePage::PAGE, ['label' => 'current']));
        self::assertSame('node', $router->nodePageAcceptKey($slots[0], 'node-B'));
        self::assertSame($slots, iterator_to_array($router->nodePageCandidates('node-B'), false));
        $router->updatePageSubscription(PerInstanceNodePage::PAGE,
            new WebSocketPageUpdateSubscriptionSignalDTO('node', PerInstanceNodePage::PAGE, ['nodeId' => 'node-C']));
        self::assertNull($router->nodePageAcceptKey($slots[0], 'node-B'));
        self::assertSame([], iterator_to_array($router->nodePageCandidates('node-B')));
        $new = iterator_to_array($router->nodePageCandidates('node-C'), false);
        self::assertCount(1, $new);
        self::assertSame('node', $router->nodePageAcceptKey($new[0], 'node-C'));
    }
}
