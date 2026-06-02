<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Router\ActionErrorSignalDataInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
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
    /** Signal-to-page route config for non-action routed signals. */
    private SignalRouteConfig $signalRoutes;

    /**
     * Creates page signal router with factory and action routes.
     *
     * @param AbstractPageFactory $pageFactory Page factory for resolving pages
     * @param ActionRouteConfig $actionRoutes Action-to-page route config
     * @param ?SignalRouteConfig $signalRoutes Optional signal-to-page route config; empty config when null
     */
    public function __construct(
        private AbstractPageFactory $pageFactory,
        private ActionRouteConfig $actionRoutes,
        ?SignalRouteConfig $signalRoutes = null,
    ) {
        $this->signalRoutes = $signalRoutes ?? new SignalRouteConfig();
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
            $this->sendSubscriptionError($pageInstance, $page, $data->acceptKey, $e->httpCode, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error("Unexpected page subscription error: page={$page}, exception={$e->getMessage()}");
            $this->sendSubscriptionError($pageInstance, $page, $data->acceptKey, 500, 'internal_error', 'Internal error during subscription');
        }
    }

    /**
     * Dispatch page update subscription signal to page handler
     *
     * Mirrors {@see self::dispatchPageSubscribe} error handling: a
     * PageSubscriptionException from onUpdateSubscription (e.g. re-validating the
     * merged route params) becomes a structured subscription error signal, and
     * any other exception becomes a generic internal error. The subscription is
     * preserved either way.
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

        try {
            $pageInstance->onUpdateSubscription($data->acceptKey, new PageRouteParams($data->params));
        } catch (PageSubscriptionException $e) {
            Logger::info("Page update subscription error: page={$page}, httpCode={$e->httpCode}, error={$e->errorCode}, message={$e->getMessage()}");
            $this->sendSubscriptionError($pageInstance, $page, $data->acceptKey, $e->httpCode, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error("Unexpected page update subscription error: page={$page}, exception={$e->getMessage()}");
            $this->sendSubscriptionError($pageInstance, $page, $data->acceptKey, 500, 'internal_error', 'Internal error during subscription update');
        }
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

        try {
            $pageInstance->onAction($data->acceptKey, $data->action, $dto);
        } catch (Throwable $e) {
            $pageInstance->onActionException($data->acceptKey, $data->action, $dto, $e);
        }
    }

    /**
     * Dispatch binary frame signal to its configured page handler.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function dispatchFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $pageInstance = $this->resolveSignalPage(SignalTypeConstants::FRAME_BINARY, $name);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onSignalFrameBinary($data, $source, $name);
    }

    /**
     * Dispatch agent-to-agent signal to its configured page handler.
     *
     * @param AgentSignalData $data Wrapped agent signal payload
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws AgentException
     * @throws ValidationException When a validation failure cannot be mapped to an action error
     */
    public function dispatchAgentSignal(AgentSignalData $data, string $source, string $name): void
    {
        $pageInstance = $this->resolveSignalPage(SignalTypeConstants::AGENT_SIGNAL, $name);
        if ($pageInstance === null) {
            return;
        }

        $innerPayload = $this->pageFactory->createPageSignalPayloadDTO(
            SignalTypeConstants::AGENT_SIGNAL,
            $name,
            $data->data,
        );
        $signalData = $innerPayload === $data->data ? $data : new AgentSignalData($innerPayload);

        try {
            $pageInstance->onSignalAgent($signalData, $source, $name);
        } catch (ValidationException $e) {
            $this->dispatchAgentSignalActionException($pageInstance, $signalData, $e);
        }
    }

    /**
     * Dispatch cron signal to its configured page handler.
     *
     * @param SignalDataInterface $data Cron payload
     * @param string $source Signal source
     * @param string $name Cron job name
     */
    public function dispatchCron(SignalDataInterface $data, string $source, string $name): void
    {
        $pageInstance = $this->resolveSignalPage(SignalTypeConstants::CRON, $name);
        if ($pageInstance === null) {
            return;
        }

        $pageInstance->onSignalCron($data, $source, $name);
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

    /**
     * Resolve page instance for a signal route.
     *
     * @param string $signalType Signal type constant
     * @param string $name Signal name
     * @return ?AbstractPage Routed page instance or null when no route/page exists
     */
    private function resolveSignalPage(string $signalType, string $name): ?AbstractPage
    {
        $page = $this->signalRoutes->getPageForSignal($signalType, $name);
        if ($page === null) {
            return null;
        }

        return $this->resolvePage($page);
    }

    /**
     * Queues a structured subscription error signal to the originating connection.
     *
     * Shared by the subscribe and update-subscription dispatchers so both emit
     * the same SUBSCRIPTION_PAGE_ERROR contract without tearing down the connection.
     *
     * @param AbstractPage $pageInstance Page whose agent owns the signal source
     * @param string $page Page name that failed
     * @param string $acceptKey Target connection acceptKey
     * @param int $httpCode HTTP status code for the error
     * @param string $errorCode Machine-readable error code
     * @param string $message Human-readable error message
     */
    private function sendSubscriptionError(
        AbstractPage $pageInstance,
        string $page,
        string $acceptKey,
        int $httpCode,
        string $errorCode,
        string $message,
    ): void {
        Hilos::$sr->queueSignal(
            signalSource: $pageInstance->getAgent()->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalConstants::SUBSCRIPTION_PAGE_ERROR),
            signalData: new WebSocketSignalData(
                data: new PageSubscriptionErrorSignalData(
                    page: $page,
                    httpCode: $httpCode,
                    errorCode: $errorCode,
                    message: $message,
                ),
                targetAcceptKey: $acceptKey,
            ),
        );
    }

    /**
     * Routes a user-facing validation failure from an async agent signal through the page action error hook.
     *
     * @param AbstractPage $pageInstance Page that handled the routed signal
     * @param AgentSignalData $data Wrapped agent signal payload
     * @param ValidationException $e Validation failure to surface to the originating client
     * @throws ValidationException When the signal payload does not expose action error context
     */
    private function dispatchAgentSignalActionException(
        AbstractPage $pageInstance,
        AgentSignalData $data,
        ValidationException $e,
    ): void {
        $actionErrorData = $data->data;
        if (!$actionErrorData instanceof ActionErrorSignalDataInterface) {
            throw $e;
        }

        $acceptKey = $actionErrorData->getAcceptKey();
        if ($acceptKey === '') {
            throw $e;
        }

        $action = $actionErrorData->getActionErrorName();
        $pageInstance->onActionException(
            $acceptKey,
            $action,
            $this->pageFactory->createActionPayloadDTO(
                $action,
                $actionErrorData->getActionErrorPayload(),
            ),
            $e,
        );
    }
}
