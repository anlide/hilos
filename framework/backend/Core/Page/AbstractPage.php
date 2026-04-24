<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Throwable;

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
     * Handle page subscription.
     *
     * Called when a client subscribes to this page. Override in child classes
     * when subscription logic is needed. Route params are available through
     * the typed accessors on {@see PageRouteParams}; family-level abstract
     * pages typically convert them into an {@see AbstractPageSubscribeParamsDTO}
     * subclass before dispatching to a page-specific hook.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
    }

    /**
     * Handle page subscription update.
     *
     * Called when a client updates params of an existing page subscription.
     * Default is a no-op; override in child classes when partial-state
     * refresh is needed. Route params arrive as a merged snapshot for the
     * subscription after applying the update payload.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Merged route params for the subscription
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
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
     * @throws AgentUnknownActionException When the page does not support actions
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        throw new AgentUnknownActionException("Unknown action: {$action}");
    }

    /**
     * Handle action exceptions.
     *
     * Optional hook called by PageSignalRouter when onAction() throws. Override
     * only when the page has a more specific user-facing error contract.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @param Throwable $e Action failure
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->sendToUser(
            SignalConstants::ACTION_ERROR,
            $acceptKey,
            new PageActionErrorSignalData($action, $e->getMessage()),
        );
    }

    /**
     * Send signal to a specific user (WebSocket connection by acceptKey).
     *
     * Uses agent's signal source for routing context without depending on the
     * agent's concrete type.
     *
     * @param string $signalName Signal name
     * @param string $acceptKey Target connection acceptKey
     * @param SignalDataInterface $data Signal payload
     */
    protected function sendToUser(string $signalName, string $acceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }

    /**
     * Send signal to all users (broadcast). Optionally exclude one connection.
     *
     * Uses agent's signal source for routing context without depending on the
     * agent's concrete type.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     */
    protected function sendToAllUsers(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Emit a DB-layer change; the daemon signal mapper expands it to WebSocket deliveries.
     *
     * Uses agent's signal source for routing context without depending on the
     * agent's concrete type.
     *
     * @param string $eventKey Logical event name for the project mapper
     * @param EmitDbChangeSignalData $data DB change payload
     */
    protected function emitChangeDb(string $eventKey, EmitDbChangeSignalData $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::EMIT_DB_CHANGE),
            signalName: new SignalName($eventKey),
            signalData: $data,
        );
    }
}
