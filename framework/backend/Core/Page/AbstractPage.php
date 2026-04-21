<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * AbstractPage - Abstract base class for page handlers.
 *
 * Provides base implementation for page-specific logic.
 * Each page handles its own subscribe, unsubscribe, and action logic.
 * Signal router is available globally via Hilos::$sr.
 */
abstract class AbstractPage
{
    /** @var string Page name or identifier, override in child classes */
    public const string PAGE = '';

    /** @var PageAgentInterface Agent instance for page operations */
    protected PageAgentInterface $agent;

    /**
     * Creates page with agent instance.
     *
     * @param PageAgentInterface $agent Agent instance
     */
    public function __construct(PageAgentInterface $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Get page name (identifier)
     *
     * @return string Page name/identifier from PAGE constant
     */
    public function getPageName(): string
    {
        return static::PAGE;
    }

    /**
     * Get page agent instance
     *
     * @return PageAgentInterface Agent instance
     */
    public function getAgent(): PageAgentInterface
    {
        return $this->agent;
    }

    /**
     * Handle page subscription
     *
     * Called when a client subscribes to this page.
     * Override in child classes when subscription logic is needed.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
    }

    /**
     * Handle page unsubscription
     *
     * Called when a client unsubscribes from this page.
     * Override in child classes when unsubscription logic is needed.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
    }

    /**
     * Handle action signal
     *
     * Called when a client sends an action signal on this page.
     * Override in child classes when action handling is needed.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
    }
}
