<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Utils\Logger;
use Throwable;

/**
 * PageSignalRouter - Routes page signals to page handlers.
 *
 * Resolves page instances and dispatches subscribe/unsubscribe/action events.
 */
class PageSignalRouter
{
    /**
     * Creates page signal router with factory and action routes.
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
     * Catches PageSubscriptionException and sends structured error signal while
     * preserving the subscription. Catches any other exception as internal error.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name fallback)
     */
    public function dispatchPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
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

        try {
            $pageInstance->onSubscribe($data->acceptKey, new PageRouteParams($data->params));
        } catch (PageSubscriptionException $e) {
            Logger::info("Page subscription error: page={$page}, httpCode={$e->httpCode}, error={$e->errorCode}, message={$e->getMessage()}");
            Hilos::$sr->queueSignal(
                signalSource: $pageInstance->getAgent()->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName(SignalConstants::SUBSCRIPTION_PAGE_ERROR),
                signalData: new WebSocketSignalData(
                    data: new PageSubscriptionErrorSignalData(
                        page: $page,
                        httpCode: $e->httpCode,
                        errorCode: $e->errorCode,
                        message: $e->getMessage(),
                    ),
                    targetAcceptKey: $data->acceptKey,
                ),
            );
        } catch (Throwable $e) {
            Logger::error("Unexpected page subscription error: page={$page}, exception={$e->getMessage()}");
            Hilos::$sr->queueSignal(
                signalSource: $pageInstance->getAgent()->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName(SignalConstants::SUBSCRIPTION_PAGE_ERROR),
                signalData: new WebSocketSignalData(
                    data: new PageSubscriptionErrorSignalData(
                        page: $page,
                        httpCode: 500,
                        errorCode: 'internal_error',
                        message: 'Internal error during subscription',
                    ),
                    targetAcceptKey: $data->acceptKey,
                ),
            );
        }
    }

    /**
     * Dispatch page update subscription signal to page handler
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     */
    public function dispatchPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        $page = $name;
        if ($page === '') {
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onUpdateSubscription($data->acceptKey, new PageRouteParams($data->params));
    }

    /**
     * Dispatch page unsubscribe signal to page handler
     *
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name (page name)
     */
    public function dispatchPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        $page = $name;
        if ($page === '') {
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
     * Creates ActionPayloadDTO via PageFactory.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     */
    public function dispatchAction(WebSocketActionSignalDTO $data, string $source): void
    {
        $page = $this->actionRoutes->getPageForAction($data->action) ?? '';

        if ($page === '') {
            Logger::error("Action routing failed: action={$data->action}, acceptKey={$data->acceptKey}");
            return;
        }

        $pageInstance = $this->resolvePage($page);
        if ($pageInstance === null) {
            return;
        }

        // Create typed DTO via PageFactory
        $dto = $this->pageFactory->createActionPayloadDTO($data->action, $data->data);

        $pageInstance->onAction($data->acceptKey, $data->action, $dto);
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
