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
 * Centralizes WebSocket page routing logic and keeps page/client mapping
 * at framework level. Domain agents provide user resolution and hooks.
 */
abstract class AbstractPageAgent extends AbstractAgent
{
    /** @var ?AbstractPageFactory Page factory instance */
    private ?AbstractPageFactory $pageFactory = null;

    /**
     * In-memory mapping between websocket client id and page name.
     *
     * @var array<string,string>
     */
    private array $clientIdToPage = [];

    /**
     * Provide user object for a handshake-based page subscribe.
     *
     * @param WebSocketHandshakeSignalDTO $data
     * @param string $clientId
     * @param string $page
     * @return mixed
     */
    abstract protected function handleHandshakeSubscription(
        WebSocketHandshakeSignalDTO $data,
        string $clientId,
        string $page,
    ): mixed;

    /**
     * Resolve user object for a known client (non-handshake subscribe).
     *
     * @param string $clientId
     * @return mixed|null
     */
    abstract protected function resolveUserForClient(string $clientId): mixed;

    /**
     * Resolve userId for a known client (unsubscribe/action).
     *
     * @param string $clientId
     * @return ?int
     */
    abstract protected function resolveUserIdForClient(string $clientId): ?int;

    /**
     * Assign page factory used for page routing.
     *
     * @param AbstractPageFactory $pageFactory
     * @return void
     */
    protected function setPageFactory(AbstractPageFactory $pageFactory): void
    {
        $this->pageFactory = $pageFactory;
    }

    /**
     * Get page factory if configured.
     *
     * @return ?AbstractPageFactory
     */
    protected function getPageFactory(): ?AbstractPageFactory
    {
        return $this->pageFactory;
    }

    /**
     * Remember client -> page mapping.
     *
     * @param string $clientId
     * @param string $page
     * @return void
     */
    protected function rememberClientPage(string $clientId, string $page): void
    {
        $this->clientIdToPage[$clientId] = $page;
    }

    /**
     * Resolve page by client id.
     *
     * @param string $clientId
     * @return ?string
     */
    protected function resolveClientPage(string $clientId): ?string
    {
        return $this->clientIdToPage[$clientId] ?? null;
    }

    /**
     * Forget client -> page mapping.
     *
     * @param string $clientId
     * @return void
     */
    protected function forgetClientPage(string $clientId): void
    {
        unset($this->clientIdToPage[$clientId]);
    }

    /**
     * Get client ids filtered by page (or all).
     *
     * @param ?string $page
     * @return string[]
     */
    protected function getClientIdsForPage(?string $page = null): array
    {
        if ($page === null) {
            return array_keys($this->clientIdToPage);
        }

        $clientIds = [];
        foreach ($this->clientIdToPage as $clientId => $mappedPage) {
            if ($mappedPage === $page) {
                $clientIds[] = $clientId;
            }
        }

        return $clientIds;
    }

    /**
     * Cleanup after client disconnects/unsubscribes.
     *
     * @param string $clientId
     * @return void
     */
    protected function removeClient(string $clientId): void
    {
        $this->forgetClientPage($clientId);
        $this->onClientRemoved($clientId);
    }

    /**
     * Hook: called after client mapping is removed.
     *
     * @param string $clientId
     * @return void
     */
    protected function onClientRemoved(string $clientId): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after successful page subscribe.
     *
     * @param string $clientId
     * @param string $page
     * @param mixed $user
     * @return void
     */
    protected function onClientSubscribed(string $clientId, string $page, mixed $user): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after subscription update.
     *
     * @param string $clientId
     * @param string $page
     * @return void
     */
    protected function onClientSubscriptionUpdated(string $clientId, string $page): void
    {
        // Default: no-op
    }

    /**
     * Hook: called when client re-subscribes or updates page.
     *
     * @param string $clientId
     * @param ?string $page
     * @return void
     */
    protected function onClientResubscribed(string $clientId, ?string $page): void
    {
        // Default: no-op
    }

    /**
     * Hook: called before page unsubscribe is processed.
     * Return true to short-circuit default handling.
     *
     * @param string $clientId
     * @param int $userId
     * @param string $page
     * @return bool
     */
    protected function beforePageUnsubscribe(string $clientId, int $userId, string $page): bool
    {
        return false;
    }

    /**
     * Hook: called when action signal is received (pre-dispatch).
     *
     * @param string $source
     * @param string $name
     * @param string $clientId
     * @param int $userId
     * @param string $payload
     * @return void
     */
    protected function onActionReceived(
        string $source,
        string $name,
        string $clientId,
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
            $clientId = $data->clientId;
            $page = $name;
            Logger::logAgentDebug($this->getId(), "Page subscribe signal received: source={$source}, client={$clientId}, page={$page}");

            if ($clientId === '') {
                throw new RuntimeException("Client ID is required but not provided or empty");
            }

            $user = $this->handleHandshakeSubscription($data, $clientId, $page);
            $this->rememberClientPage($clientId, $page);
            $this->onClientResubscribed($clientId, $page);
            $this->dispatchPageSubscribe($clientId, $page, $user, true);
            $this->onClientSubscribed($clientId, $page, $user);
            return;
        }

        if (!($data instanceof WebSocketSubscribeSignalDTO)) {
            $dataType = get_class($data);
            Logger::logAgentError($this->getId(), "Invalid signal data type: expected WebSocketHandshakeSignalDTO or WebSocketSubscribeSignalDTO, got {$dataType}");
            throw new RuntimeException("Expected WebSocketHandshakeSignalDTO or WebSocketSubscribeSignalDTO, got {$dataType}");
        }

        $clientId = $data->clientId;
        $page = $data->page ?? $name;

        Logger::logAgentDebug($this->getId(), "Page subscribe signal received: source={$source}, client={$clientId}, page={$page}");

        if ($clientId === '') {
            Logger::logAgentError($this->getId(), "Client ID is required but not provided or empty (page subscribe)");
            return;
        }

        $user = $this->resolveUserForClient($clientId);
        if ($user === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve user for clientId={$clientId} (page subscribe)");
            return;
        }

        if ($page === null || $page === '') {
            Logger::logAgentError($this->getId(), "Page is required but not provided or empty (page subscribe)");
            return;
        }

        $this->rememberClientPage($clientId, $page);
        $this->onClientResubscribed($clientId, $page);
        $this->dispatchPageSubscribe($clientId, $page, $user, false);
        $this->onClientSubscribed($clientId, $page, $user);
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

        $clientId = $data->clientId;
        $page = $data->page ?? $name;

        Logger::logAgentDebug($this->getId(), "Page update subscription signal received: source={$source}, client={$clientId}, page={$page}");

        if ($clientId === '') {
            Logger::logAgentError($this->getId(), "Client ID is required but not provided or empty (page update subscription)");
            return;
        }

        if ($page !== null && $page !== '') {
            $this->rememberClientPage($clientId, $page);
        }

        $this->onClientResubscribed($clientId, $page);

        if ($page === null || $page === '') {
            return;
        }

        $this->onClientSubscriptionUpdated($clientId, $page);
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

        $clientId = $data->clientId;
        $page = $name;

        Logger::logAgentDebug($this->getId(), "Page unsubscribe signal received: source={$source}, client={$clientId}, page={$page}");

        if ($clientId === '') {
            Logger::logAgentError($this->getId(), "Client ID is required but not provided or empty (page unsubscribe)");
            return;
        }

        $userId = $this->resolveUserIdForClient($clientId);
        if ($userId === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve userId for clientId={$clientId} (page unsubscribe)");
            $this->removeClient($clientId);
            return;
        }

        $resolvedPage = $this->resolveClientPage($clientId) ?? $page;

        if ($this->beforePageUnsubscribe($clientId, $userId, $resolvedPage)) {
            return;
        }

        $pageFactory = $this->pageFactory;
        if ($pageFactory === null || !$pageFactory->hasPage($resolvedPage)) {
            Logger::logAgentError($this->getId(), "Unknown page unsubscribe: {$resolvedPage}");
            $this->removeClient($clientId);
            return;
        }

        try {
            $pageInstance = $pageFactory->getPage($resolvedPage);
        } catch (PageNotFoundException $exception) {
            Logger::logAgentError($this->getId(), "Unknown page unsubscribe: {$resolvedPage}");
            $this->removeClient($clientId);
            return;
        }

        $pageInstance->onUnsubscribe($clientId, $userId);
        $this->removeClient($clientId);
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

        $clientId = $data->clientId;
        $payload = $data->payload;
        $action = $name;

        if ($clientId === '') {
            Logger::logAgentError($this->getId(), "Client ID is required but not provided or empty (action)");
            return;
        }

        $userId = $this->resolveUserIdForClient($clientId);
        if ($userId === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve userId for clientId={$clientId} (action)");
            return;
        }

        $this->onActionReceived($source, $name, $clientId, $userId, $payload);

        $page = $this->resolveClientPage($clientId);
        if ($page === null) {
            Logger::logAgentError($this->getId(), "Unable to resolve page for clientId={$clientId} (action)");
            return;
        }

        $pageFactory = $this->pageFactory;
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

        $pageInstance->onAction($clientId, $userId, $action, $payload);
    }

    /**
     * Dispatch page subscribe to page instance.
     *
     * @param string $clientId
     * @param string $page
     * @param mixed $user
     * @param bool $strict
     * @return void
     */
    private function dispatchPageSubscribe(string $clientId, string $page, mixed $user, bool $strict): void
    {
        $pageFactory = $this->pageFactory;
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

        $pageInstance->onSubscribe($clientId, $user);
    }
}
