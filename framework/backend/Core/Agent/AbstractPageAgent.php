<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\WebSocket\WebSocketFrameSignalDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUpdateSubscriptionSignalDTO;
use Hilos\Exception\Page\PageNotFoundException;
use Hilos\Logging\Logger\Logger;
use RuntimeException;

/**
 * AbstractPageAgent - Base agent with page routing helpers
 *
 * Centralizes WebSocket page routing logic and keeps page/acceptKey mapping
 * at framework level. Domain agents provide user resolution and hooks.
 */
abstract class AbstractPageAgent extends AbstractAgent
{
    /** @var ?AbstractPageFactory Page factory instance */
    abstract protected function getPageFactory(): ?AbstractPageFactory;

    /**
     * In-memory mapping between websocket accept key and page name.
     *
     * @var array<string,string>
     */
    private array $acceptKeyToPage = [];

    /**
     * Provide user object for a handshake-based page subscribe.
     *
     * @param WebSocketHandshakeSignalDTO $data
     * @param string $acceptKey
     * @param string $page
     * @return mixed
     */
    abstract protected function handleHandshakeSubscription(
        WebSocketHandshakeSignalDTO $data,
        string $acceptKey,
        string $page,
    ): mixed;

    /**
     * Remember acceptKey -> page mapping.
     *
     * @param string $acceptKey
     * @param string $page
     * @return void
     */
    protected function rememberAcceptKeyPage(string $acceptKey, string $page): void
    {
        $this->acceptKeyToPage[$acceptKey] = $page;
    }

    /**
     * Resolve page by accept key.
     *
     * @param string $acceptKey
     * @return ?string
     */
    protected function resolveAcceptKeyPage(string $acceptKey): ?string
    {
        return $this->acceptKeyToPage[$acceptKey] ?? null;
    }

    /**
     * Forget acceptKey -> page mapping.
     *
     * @param string $acceptKey
     * @return void
     */
    protected function forgetAcceptKeyPage(string $acceptKey): void
    {
        unset($this->acceptKeyToPage[$acceptKey]);
    }

    /**
     * Get accept keys filtered by page (or all).
     *
     * @param ?string $page
     * @return string[]
     */
    protected function getAcceptKeysForPage(?string $page = null): array
    {
        if ($page === null) {
            return array_keys($this->acceptKeyToPage);
        }

        $acceptKeys = [];
        foreach ($this->acceptKeyToPage as $acceptKey => $mappedPage) {
            if ($mappedPage === $page) {
                $acceptKeys[] = $acceptKey;
            }
        }

        return $acceptKeys;
    }

    /**
     * Cleanup after accept key disconnects/unsubscribes.
     *
     * @param string $acceptKey
     * @return void
     */
    protected function removeAcceptKey(string $acceptKey): void
    {
        $this->forgetAcceptKeyPage($acceptKey);
        $this->onAcceptKeyRemoved($acceptKey);
    }

    /**
     * Hook: called after acceptKey mapping is removed.
     *
     * @param string $acceptKey
     * @return void
     */
    protected function onAcceptKeyRemoved(string $acceptKey): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after successful page subscribe.
     *
     * @param string $acceptKey
     * @param string $page
     * @param mixed $user
     * @return void
     */
    protected function onAcceptKeySubscribed(string $acceptKey, string $page, mixed $user): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after subscription update.
     *
     * @param string $acceptKey
     * @param string $page
     * @return void
     */
    protected function onAcceptKeySubscriptionUpdated(string $acceptKey, string $page): void
    {
        // Default: no-op
    }

    /**
     * Hook: called when client re-subscribes or updates page.
     *
     * @param string $acceptKey
     * @param ?string $page
     * @return void
     */
    protected function onAcceptKeyResubscribed(string $acceptKey, ?string $page): void
    {
        // Default: no-op
    }

    /**
     * Hook: called before page unsubscribe is processed.
     * Return true to short-circuit default handling.
     *
     * @param string $acceptKey
     * @param int $userId
     * @param string $page
     * @return bool
     */
    protected function beforePageUnsubscribe(string $acceptKey, int $userId, string $page): bool
    {
        return false;
    }

    /**
     * Hook: called when action signal is received (pre-dispatch).
     *
     * @param string $source
     * @param string $name
     * @param string $acceptKey
     * @param int $userId
     * @param string $payload
     * @return void
     */
    protected function onActionReceived(
        string $source,
        string $name,
        string $acceptKey,
        int $userId,
        string $payload,
    ): void {
        // Default: no-op
    }

    /**
     * Handle page subscribe signal with shared routing logic.
     *
     * @param string $source
     * @param string $name
     * @param SignalDataInterface $data
     * @return void
     */
    public function onSignalPageSubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        if ($data instanceof WebSocketHandshakeSignalDTO) {
            $acceptKey = $data->acceptKey;
            $page = $name;
            Logger::logAgentDebug($this->getId(), "Page subscribe signal received: source={$source}, acceptKey={$acceptKey}, page={$page}");

            if ($acceptKey === '') {
                throw new RuntimeException("Accept key is required but not provided or empty");
            }

            $user = $this->handleHandshakeSubscription($data, $acceptKey, $page);
            $this->rememberAcceptKeyPage($acceptKey, $page);
            $this->onAcceptKeyResubscribed($acceptKey, $page);
            $this->dispatchPageSubscribe($acceptKey, $page, $user, true);
            $this->onAcceptKeySubscribed($acceptKey, $page, $user);
            return;
        }

        if (!($data instanceof WebSocketSubscribeSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketHandshakeSignalDTO or WebSocketSubscribeSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketHandshakeSignalDTO or WebSocketSubscribeSignalDTO, got {$dataType}");
        }

        // Non-handshake subscribe requires user resolution, which is disabled for now.
        return;
    }

    /**
     * Handle page update subscription signal with shared routing logic.
     *
     * @param string $source
     * @param string $name
     * @param SignalDataInterface $data
     * @return void
     */
    public function onSignalPageUpdateSubscription(string $source, string $name, SignalDataInterface $data): void
    {
        if (!($data instanceof WebSocketUpdateSubscriptionSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketUpdateSubscriptionSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketUpdateSubscriptionSignalDTO, got {$dataType}");
        }

        $acceptKey = $data->acceptKey;
        $page = $data->page ?? $name;

        Logger::logAgentDebug($this->getId(), "Page update subscription signal received: source={$source}, acceptKey={$acceptKey}, page={$page}");

        if ($acceptKey === '') {
            Logger::logAgentError($this->getId(), "Accept key is required but not provided or empty (page update subscription)");
            return;
        }

        if ($page !== null && $page !== '') {
            $this->rememberAcceptKeyPage($acceptKey, $page);
        }

        $this->onAcceptKeyResubscribed($acceptKey, $page);

        if ($page === null || $page === '') {
            return;
        }

        $this->onAcceptKeySubscriptionUpdated($acceptKey, $page);
    }

    /**
     * Handle page unsubscribe signal with shared routing logic.
     *
     * @param string $source
     * @param string $name
     * @param SignalDataInterface $data
     * @return void
     */
    public function onSignalPageUnsubscribe(string $source, string $name, SignalDataInterface $data): void
    {
        if (!($data instanceof WebSocketUnsubscribeSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketUnsubscribeSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketUnsubscribeSignalDTO, got {$dataType}");
        }

        $acceptKey = $data->acceptKey;
        $page = $name;

        Logger::logAgentDebug($this->getId(), "Page unsubscribe signal received: source={$source}, acceptKey={$acceptKey}, page={$page}");

        if ($acceptKey === '') {
            Logger::logAgentError($this->getId(), "Accept key is required but not provided or empty (page unsubscribe)");
            return;
        }

        // Unsubscribe requires user resolution, which is disabled for now.
        return;

        $resolvedPage = $this->resolveAcceptKeyPage($acceptKey) ?? $page;

        if ($this->beforePageUnsubscribe($acceptKey, $userId, $resolvedPage)) {
            return;
        }

        $pageFactory = $this->getPageFactory();
        if ($pageFactory === null || !$pageFactory->hasPage($resolvedPage)) {
            Logger::logAgentError($this->getId(), "Unknown page unsubscribe: {$resolvedPage}");
            $this->removeAcceptKey($acceptKey);
            return;
        }

        try {
            $pageInstance = $pageFactory->getPage($resolvedPage);
        } catch (PageNotFoundException $exception) {
            Logger::logAgentError($this->getId(), "Unknown page unsubscribe: {$resolvedPage}");
            $this->removeAcceptKey($acceptKey);
            return;
        }

        $pageInstance->onUnsubscribe($acceptKey, $userId);
        $this->removeAcceptKey($acceptKey);
    }

    /**
     * Handle action signal with shared routing logic.
     *
     * @param string $source
     * @param string $name
     * @param SignalDataInterface $data
     * @return void
     */
    public function onSignalAction(string $source, string $name, SignalDataInterface $data): void
    {
        if (!($data instanceof WebSocketFrameSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketFrameSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketFrameSignalDTO, got {$dataType}");
        }

        $acceptKey = $data->acceptKey;
        $payload = $data->payload;
        $action = $name;

        if ($acceptKey === '') {
            Logger::logAgentError($this->getId(), "Accept key is required but not provided or empty (action)");
            return;
        }

        // Action requires user resolution, which is disabled for now.
        return;

        $this->onActionReceived($source, $name, $acceptKey, $userId, $payload);

        $page = $this->resolveAcceptKeyPage($acceptKey);
        if ($page === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve page for acceptKey={$acceptKey} (action)");
            return;
        }

        $pageFactory = $this->getPageFactory();
        if ($pageFactory === null || !$pageFactory->hasPage($page)) {
            Logger::logAgentError($this->getId(), "Unknown page for action: {$page}");
            return;
        }

        try {
            $pageInstance = $pageFactory->getPage($page);
        } catch (PageNotFoundException $exception) {
            Logger::logAgentError($this->getId(), "Unknown page for action: {$page}");
            return;
        }

        $pageInstance->onAction($acceptKey, $userId, $action, $payload);
    }

    /**
     * Dispatch page subscribe to page instance.
     *
     * @param string $acceptKey
     * @param string $page
     * @param mixed $user
     * @param bool $strict
     * @return void
     */
    private function dispatchPageSubscribe(string $acceptKey, string $page, mixed $user, bool $strict): void
    {
        $pageFactory = $this->getPageFactory();
        if ($pageFactory === null || !$pageFactory->hasPage($page)) {
            Logger::logAgentError($this->getId(), "Unknown page subscription: {$page}");
            if ($strict) {
                throw new RuntimeException("Unknown page subscription: {$page}");
            }
            return;
        }

        try {
            $pageInstance = $pageFactory->getPage($page);
        } catch (PageNotFoundException $exception) {
            Logger::logAgentError($this->getId(), "Unknown page subscription: {$page}");
            if ($strict) {
                throw new RuntimeException("Unknown page subscription: {$page}");
            }
            return;
        }

        $pageInstance->onSubscribe($acceptKey, $user);
    }
}
