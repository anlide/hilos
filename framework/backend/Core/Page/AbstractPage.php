<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Router\SignalRouter;

/**
 * AbstractPage - Abstract base class for page handlers
 *
 * Provides base implementation for page-specific logic.
 * Each page handles its own subscribe, unsubscribe, and action logic.
 */
abstract class AbstractPage
{
    /** @var SignalRouter Signal router for sending signals */
    protected SignalRouter $signalRouter;

    /**
     * Constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct(SignalRouter $signalRouter)
    {
        $this->signalRouter = $signalRouter;
    }

    /**
     * Get page name (identifier)
     *
     * @return string Page name/identifier
     */
    abstract public function getPageName(): string;

    /**
     * Handle page subscription
     *
     * Called when a client subscribes to this page.
     *
     * @param string $acceptKey WebSocket accept key
     */
    abstract public function onSubscribe(string $acceptKey): void;

    /**
     * Handle page unsubscription
     *
     * Called when a client unsubscribes from this page.
     *
     * @param string $acceptKey WebSocket accept key
     */
    abstract public function onUnsubscribe(string $acceptKey): void;

    /**
     * Handle action signal
     *
     * Called when a client sends an action signal on this page.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param string $payload Action payload (raw string, usually JSON)
     */
    abstract public function onAction(string $acceptKey, string $action, string $payload): void;
}
