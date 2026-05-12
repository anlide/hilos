<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\BrowserSubscriptionEvent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the browser subscription event buffer.
 */
final class BrowserContextEventBufferTest extends TestCase
{
    public function testFlushKeepsFrameworkEventsAsObjectsAndDrainsBuffer(): void
    {
        $context = new BrowserContextEventBufferTestContext();
        $pageSubscribe = new WebSocketPageSubscribeSignalDTO('ak-1', 'main', ['room' => '42']);
        $pageUnsubscribe = new WebSocketPageUnsubscribeSignalDTO('ak-2');
        $pageUpdate = new WebSocketPageUpdateSubscriptionSignalDTO('ak-3', 'profile', ['tab' => 'info']);
        $groupSubscribe = new WebSocketGroupSubscribeSignalDTO('ak-4', 'admins', ['scope' => 'full']);
        $groupUnsubscribe = new WebSocketGroupUnsubscribeSignalDTO('ak-5', 'admins');
        $groupUpdate = new WebSocketGroupUpdateSubscriptionSignalDTO('ak-6', 'admins', ['scope' => 'audit']);

        $context->recordPageSubscribed($pageSubscribe, 'websocket', 'page_subscribe');
        $context->recordPageUnsubscribed($pageUnsubscribe, 'websocket', 'page_unsubscribe');
        $context->recordPageSubscriptionUpdated($pageUpdate, 'websocket', 'page_update_subscription');
        $context->recordGroupSubscribed($groupSubscribe, 'websocket', 'group_subscribe');
        $context->recordGroupUnsubscribed($groupUnsubscribe, 'websocket', 'group_unsubscribe');
        $context->recordGroupSubscriptionUpdated($groupUpdate, 'websocket', 'group_update_subscription');

        $this->assertTrue($context->hasSubscriptionEvents());

        $context->flushToSignalRouter();

        $this->assertFalse($context->hasSubscriptionEvents());
        $this->assertCount(6, $context->collapsedEvents);
        $this->assertSame($context->collapsedEvents, $context->dispatchedEvents);

        $this->assertSame(BrowserContext::EVENT_PAGE_SUBSCRIBED, $context->dispatchedEvents[0]->event);
        $this->assertSame($pageSubscribe, $context->dispatchedEvents[0]->signalData);
        $this->assertSame('websocket', $context->dispatchedEvents[0]->source);
        $this->assertSame('page_subscribe', $context->dispatchedEvents[0]->name);

        $this->assertSame(BrowserContext::EVENT_PAGE_UNSUBSCRIBED, $context->dispatchedEvents[1]->event);
        $this->assertSame($pageUnsubscribe, $context->dispatchedEvents[1]->signalData);
        $this->assertSame(BrowserContext::EVENT_PAGE_SUBSCRIPTION_UPDATED, $context->dispatchedEvents[2]->event);
        $this->assertSame($pageUpdate, $context->dispatchedEvents[2]->signalData);
        $this->assertSame(BrowserContext::EVENT_GROUP_SUBSCRIBED, $context->dispatchedEvents[3]->event);
        $this->assertSame($groupSubscribe, $context->dispatchedEvents[3]->signalData);
        $this->assertSame(BrowserContext::EVENT_GROUP_UNSUBSCRIBED, $context->dispatchedEvents[4]->event);
        $this->assertSame($groupUnsubscribe, $context->dispatchedEvents[4]->signalData);
        $this->assertSame(BrowserContext::EVENT_GROUP_SUBSCRIPTION_UPDATED, $context->dispatchedEvents[5]->event);
        $this->assertSame($groupUpdate, $context->dispatchedEvents[5]->signalData);
    }
}

final class BrowserContextEventBufferTestContext extends BrowserContext
{
    /** @var list<BrowserSubscriptionEvent> */
    public array $collapsedEvents = [];

    /** @var list<BrowserSubscriptionEvent> */
    public array $dispatchedEvents = [];

    public function configure(): void
    {
    }

    /**
     * Records the events that reached the collapse hook.
     */
    protected function collapseSubscriptionEvents(): void
    {
        $this->collapsedEvents = $this->subscriptionEvents;

        parent::collapseSubscriptionEvents();
    }

    /**
     * Records the events that reached the final browser dispatch hook.
     */
    protected function dispatchBrowserSignals(): void
    {
        $this->dispatchedEvents = $this->subscriptionEvents;
    }
}
