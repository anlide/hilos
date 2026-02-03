<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\DTO\WebSocket\WebSocketActionSignalDTO;
use Hilos\DTO\WebSocket\WebSocketSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUpdateSubscriptionSignalDTO;
use Hilos\Exception\Page\PageNotFoundException;
use Hilos\Logging\Logger\Logger;

/**
 * PageSignalRouter - Routes page signals to page handlers
 *
 * Resolves page instances and dispatches subscribe/unsubscribe/action events.
 */
class PageSignalRouter
{
    /**
     * Constructor
     *
     * @param AbstractPageFactory $pageFactory Page factory for resolving pages
     * @param ActionRouteConfig $actionRoutes Action-to-page route config
     */
    public function __construct(
        private AbstractPageFactory $pageFactory,
        private ActionRouteConfig $actionRoutes,
    ) {
    }

    /**
     * Dispatch page subscribe signal to page handler
     *
     * @param WebSocketSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name fallback)
     */
    public function dispatchPageSubscribe(WebSocketSubscribeSignalDTO $data, string $source, string $name): void
    {
        $page = $data->page !== '' ? $data->page : $name;
        if ($page === '') {
            Logger::error('Page subscribe without page name');
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onSubscribe($data->acceptKey);
    }

    /**
     * Dispatch page update subscription signal to page handler
     *
     * @param WebSocketUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     */
    public function dispatchPageUpdateSubscription(WebSocketUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        // Default: no-op (routing only, no page-level update yet).
    }

    /**
     * Dispatch page unsubscribe signal to page handler
     *
     * @param WebSocketUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     */
    public function dispatchPageUnsubscribe(WebSocketUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        $page = $name;
        if ($page === '') {
            Logger::error('Page unsubscribe without page name');
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onUnsubscribe($data->acceptKey);
    }

    /**
     * Dispatch action signal to page handler
     *
     * Resolves page from payload or action route configuration.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     */
    public function dispatchAction(WebSocketActionSignalDTO $data, string $source): void
    {
        $page = $data->page;
        if ($page === null || $page === '') {
            $page = $this->actionRoutes->getPageForAction($data->action) ?? '';
        }

        if ($page === '') {
            Logger::error("Action routing failed: action={$data->action}, acceptKey={$data->acceptKey}");
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        $payload = json_encode($data->data);
        if ($payload === false) {
            $payload = '{}';
        }

        $pageInstance->onAction($data->acceptKey, $data->action, $payload);
    }

    /**
     * Resolve page instance by name
     *
     * @param string $page Page name
     * @return ?AbstractPage Resolved page instance or null if not found
     */
    private function resolvePage(string $page): ?AbstractPage
    {
        if (!$this->pageFactory->hasPage($page)) {
            Logger::error("Unknown page: {$page}");
            return null;
        }

        try {
            return $this->pageFactory->getPage($page);
        } catch (PageNotFoundException $exception) {
            Logger::error("Page not found: {$page}");
            return null;
        }
    }
}
