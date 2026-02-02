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
 * Routes page signals to page handlers.
 */
class PageSignalRouter
{
    public function __construct(
        private AbstractPageFactory $pageFactory,
        private ActionRouteConfig $actionRoutes,
    ) {
    }

    public function dispatchPageSubscribe(WebSocketSubscribeSignalDTO $data, string $source): void
    {
        $page = $data->page;
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

    public function dispatchPageUpdateSubscription(WebSocketUpdateSubscriptionSignalDTO $data, string $source): void
    {
        // Default: no-op (routing only, no page-level update yet).
    }

    public function dispatchPageUnsubscribe(string $page, WebSocketUnsubscribeSignalDTO $data, string $source): void
    {
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
