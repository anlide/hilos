<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

use Hilos\Core\Browser\BrowserSubscriptionEvent;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;

/**
 * Base browser-facing context.
 *
 * Project subclasses register browser-facing state helpers during configuration.
 */
abstract class BrowserContext
{
    public const string EVENT_PAGE_SUBSCRIBED = 'page_subscribed';
    public const string EVENT_PAGE_UNSUBSCRIBED = 'page_unsubscribed';
    public const string EVENT_PAGE_SUBSCRIPTION_UPDATED = 'page_subscription_updated';
    public const string EVENT_GROUP_SUBSCRIBED = 'group_subscribed';
    public const string EVENT_GROUP_UNSUBSCRIBED = 'group_unsubscribed';
    public const string EVENT_GROUP_SUBSCRIPTION_UPDATED = 'group_subscription_updated';

    /** @var list<BrowserSubscriptionEvent> */
    protected array $subscriptionEvents = [];

    /**
     * Registers browser-facing state helpers.
     *
     * Called during Hilos::init().
     */
    abstract public function configure(): void;

    /**
     * Records a page subscribe event for the next browser flush.
     *
     * @param WebSocketPageSubscribeSignalDTO $signalData Original page subscribe signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    public function recordPageSubscribed(
        WebSocketPageSubscribeSignalDTO $signalData,
        string $source,
        string $name,
    ): void {
        $this->recordSubscriptionEvent(self::EVENT_PAGE_SUBSCRIBED, $signalData, $source, $name);
    }

    /**
     * Records a page unsubscribe event for the next browser flush.
     *
     * @param WebSocketPageUnsubscribeSignalDTO $signalData Original page unsubscribe signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    public function recordPageUnsubscribed(
        WebSocketPageUnsubscribeSignalDTO $signalData,
        string $source,
        string $name,
    ): void {
        $this->recordSubscriptionEvent(self::EVENT_PAGE_UNSUBSCRIBED, $signalData, $source, $name);
    }

    /**
     * Records a page subscription update event for the next browser flush.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $signalData Original page update signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    public function recordPageSubscriptionUpdated(
        WebSocketPageUpdateSubscriptionSignalDTO $signalData,
        string $source,
        string $name,
    ): void {
        $this->recordSubscriptionEvent(self::EVENT_PAGE_SUBSCRIPTION_UPDATED, $signalData, $source, $name);
    }

    /**
     * Records a group subscribe event for the next browser flush.
     *
     * @param WebSocketGroupSubscribeSignalDTO $signalData Original group subscribe signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    public function recordGroupSubscribed(
        WebSocketGroupSubscribeSignalDTO $signalData,
        string $source,
        string $name,
    ): void {
        $this->recordSubscriptionEvent(self::EVENT_GROUP_SUBSCRIBED, $signalData, $source, $name);
    }

    /**
     * Records a group unsubscribe event for the next browser flush.
     *
     * @param WebSocketGroupUnsubscribeSignalDTO $signalData Original group unsubscribe signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    public function recordGroupUnsubscribed(
        WebSocketGroupUnsubscribeSignalDTO $signalData,
        string $source,
        string $name,
    ): void {
        $this->recordSubscriptionEvent(self::EVENT_GROUP_UNSUBSCRIBED, $signalData, $source, $name);
    }

    /**
     * Records a group subscription update event for the next browser flush.
     *
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $signalData Original group update signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    public function recordGroupSubscriptionUpdated(
        WebSocketGroupUpdateSubscriptionSignalDTO $signalData,
        string $source,
        string $name,
    ): void {
        $this->recordSubscriptionEvent(self::EVENT_GROUP_SUBSCRIPTION_UPDATED, $signalData, $source, $name);
    }

    /**
     * Reports whether any browser subscription events are buffered.
     *
     * @return bool Whether the browser context has events waiting for flush
     */
    public function hasSubscriptionEvents(): bool
    {
        return $this->subscriptionEvents !== [];
    }

    /**
     * Drains browser subscription events at the end of the worker tick.
     */
    public function flushToSignalRouter(): void
    {
        if ($this->subscriptionEvents === []) {
            return;
        }

        $this->collapseSubscriptionEvents();
        $this->dispatchBrowserSignals();

        $this->subscriptionEvents = [];
    }

    /**
     * Collapses repeated browser events before expensive signal work.
     */
    protected function collapseSubscriptionEvents(): void
    {
        // TODO: Collapse repeated events within one tick, for example when the same field changes twice.
    }

    /**
     * Dispatches browser signals produced from collapsed subscription events.
     */
    protected function dispatchBrowserSignals(): void
    {
        // TODO: Build and queue browser-facing signals here.
    }

    /**
     * Appends one framework subscription event without transforming its DTO.
     *
     * @param string $event Browser event name
     * @param SignalDataInterface $signalData Original framework signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    private function recordSubscriptionEvent(
        string $event,
        SignalDataInterface $signalData,
        string $source,
        string $name,
    ): void {
        $this->subscriptionEvents[] = new BrowserSubscriptionEvent($event, $signalData, $source, $name);
    }
}
