<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Logger;
use Throwable;

/**
 * Base class for page handlers.
 *
 * Concrete pages own subscription, action, and routed signal hooks. Shared
 * framework state is resolved through the active Hilos facade.
 */
abstract class AbstractPage
{
    /** Page name or identifier, overridden by concrete pages. */
    public const string PAGE = '';

    /** Agent type that owns subscription signals for this page. */
    public const string SUBSCRIPTION_AGENT_TYPE = '';

    /** WebSocket actions owned by this page, keyed by action name with payload DTO class values. */
    public const array ACTIONS = [];

    /**
     * Non-action signal routes owned by this page, keyed by signal type.
     *
     * Type-wide routes use an empty list (`SignalTypeConstants::FRAME_BINARY => []`).
     * Named routes use either a list of signal name strings or
     * `signalName => SignalDataInterface` class map entries.
     */
    public const array SIGNALS = [];

    /** Browser data config declared by data-bearing pages. */
    public const array BROWSER = [];

    /** Agent instance that owns this page handler. */
    protected PageAgentInterface $agent;

    /**
     * Creates a page bound to its owning agent.
     *
     * @param PageAgentInterface $agent Agent instance
     */
    public function __construct(PageAgentInterface $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Returns the static page identifier.
     *
     * @return string Page identifier from the PAGE constant
     */
    public function getPageName(): string
    {
        return static::PAGE;
    }

    /**
     * Returns the page agent instance.
     *
     * @return PageAgentInterface Agent instance
     */
    public function getAgent(): PageAgentInterface
    {
        return $this->agent;
    }

    /**
     * Logs an info message under this page's owning agent id.
     *
     * @param string $message Message to log
     */
    protected function logAgentInfo(string $message): void
    {
        Logger::logAgentInfo($this->agent->getId(), $message);
    }

    /**
     * Logs an error message under this page's owning agent id.
     *
     * @param string $message Error message to log
     */
    protected function logAgentError(string $message): void
    {
        Logger::logAgentError($this->agent->getId(), $message);
    }

    /**
     * Logs an info message when a static page workflow only has an agent id.
     *
     * @param string $agentId Agent id to use as the log source
     * @param string $message Message to log
     */
    protected static function logAgentInfoForId(string $agentId, string $message): void
    {
        Logger::logAgentInfo($agentId, $message);
    }

    /**
     * Handles page subscription.
     *
     * Default behavior delegates to the browser layer. Override in concrete
     * pages to add domain or routing parameter checks before or instead of
     * delegating to browser state.
     *
     * Route params are available through the typed accessors on
     * PageRouteParams; family-level abstract pages typically convert
     * them into an AbstractPageSubscribeParamsDTO subclass before
     * dispatching to a page-specific hook.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws PageSubscriptionException When browser snapshot rejects the subscription
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        Hilos::$browser?->subscribeSnapshot(static::PAGE, $acceptKey, $params);
    }

    /**
     * Handles page subscription update.
     *
     * Called when a client updates params of an existing page subscription.
     * Default is a no-op; override in child classes when partial-state refresh
     * is needed. Route params arrive as a merged snapshot for the
     * subscription after applying the update payload.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Merged route params for the subscription
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
    }

    /**
     * Handles page unsubscription.
     *
     * Default is a no-op; override in child classes when unsubscription cleanup
     * is needed.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
    }

    /**
     * Handles a page action signal.
     *
     * Called when a client sends an action signal on this page.
     * Override in child classes when action handling is needed.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @throws AgentUnknownActionException When the page does not support the action
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        throw new AgentUnknownActionException("Unknown action: {$action}");
    }

    /**
     * Sends the default page action error signal.
     *
     * Optional hook called by PageSignalRouter when onAction() throws. Override
     * only when the page has a more specific user-facing error contract.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @param Throwable $e Action failure exposed to the client
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
     * Handles a routed binary frame signal.
     *
     * Default intentionally ignores the signal. Override when the page owns
     * binary frame handling for its agent.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
    }

    /**
     * Handles a routed agent-to-agent signal.
     *
     * Default intentionally ignores the signal. Override when the page owns a
     * specific agent signal workflow while the agent remains the process boundary.
     *
     * @param AgentSignalData $data Wrapped signal payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
    }

    /**
     * Handles a routed cron signal.
     *
     * Default intentionally ignores the signal. Override when a page owns the
     * scheduled workflow.
     *
     * @param SignalDataInterface $data Cron payload
     * @param string $source Signal source
     * @param string $name Cron job name
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
    }

    /**
     * Queues a signal to a specific WebSocket connection by accept key.
     *
     * Uses the owning agent signal source for routing context without depending
     * on the agent's concrete class.
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
     * Queues a broadcast signal to all WebSocket connections.
     *
     * Uses the owning agent signal source for routing context without depending
     * on the agent's concrete class.
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
     * Queues a broadcast signal to every connected WebSocket client, including
     * connections not subscribed to any page.
     *
     * Uses the owning agent signal source for routing context without depending
     * on the agent's concrete class. Use sendToAllUsers() for the usual
     * page-subscriber broadcast; reserve this for the rare all-connections case.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     */
    protected function sendToAllConnected(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

}
