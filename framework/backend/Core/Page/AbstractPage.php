<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Collection\TableMutationSignalCollection;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Logger;
use Throwable;

/**
 * Base class for page handlers.
 *
 * Concrete pages own subscribe, unsubscribe, action, and page-routed signal
 * hooks. Shared framework state is read through the Hilos facade.
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
     * Returns the static page identifier.
     *
     * @return string Page name/identifier from PAGE constant
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
     * Log an info message under this page's owning agent id.
     *
     * @param string $message Message to log
     */
    protected function logAgentInfo(string $message): void
    {
        Logger::logAgentInfo($this->agent->getId(), $message);
    }

    /**
     * Log an error message under this page's owning agent id.
     *
     * @param string $message Error message to log
     */
    protected function logAgentError(string $message): void
    {
        Logger::logAgentError($this->agent->getId(), $message);
    }

    /**
     * Log a debug message under this page's owning agent id when debug logging is enabled.
     *
     * @param string $message Debug message to log
     */
    protected function logAgentDebug(string $message): void
    {
        Logger::logAgentDebug($this->agent->getId(), $message);
    }

    /**
     * Log an info message when a static page workflow only has an agent id.
     *
     * @param string $agentId Agent id to use as the log source
     * @param string $message Message to log
     */
    protected static function logAgentInfoForId(string $agentId, string $message): void
    {
        Logger::logAgentInfo($agentId, $message);
    }

    /**
     * Log an error message when a static page workflow only has an agent id.
     *
     * @param string $agentId Agent id to use as the log source
     * @param string $message Error message to log
     */
    protected static function logAgentErrorForId(string $agentId, string $message): void
    {
        Logger::logAgentError($agentId, $message);
    }

    /**
     * Log a debug message when a static page workflow only has an agent id.
     *
     * @param string $agentId Agent id to use as the log source
     * @param string $message Debug message to log
     */
    protected static function logAgentDebugForId(string $agentId, string $message): void
    {
        Logger::logAgentDebug($agentId, $message);
    }

    /**
     * Handles page subscription.
     *
     * Default behavior delegates to the projection layer: if the page has a
     * registered {@see PageProjection}, the framework builds and sends the initial
     * snapshot through it. Override in concrete pages to add domain or routing
     * parameter checks before or instead of delegating to the projection layer.
     *
     * Route params are available through the typed accessors on
     * {@see PageRouteParams}; family-level abstract pages typically convert
     * them into an {@see AbstractPageSubscribeParamsDTO} subclass before
     * dispatching to a page-specific hook.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws PageSubscriptionException When the page rejects the subscription through its projection
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        Hilos::$projection?->subscribeSnapshot(static::PAGE, $acceptKey, $params);
    }

    /**
     * Handles page subscription update.
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
     * Handles page unsubscription.
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
     * Handles an action signal.
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
     * Handles action exceptions.
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
     * Handle a routed binary frame signal.
     *
     * Default is a no-op. Override when the page owns binary frame handling
     * for its agent.
     *
     * @param WebSocketFrameBinarySignalDTO $data Binary frame payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
    }

    /**
     * Handle a routed agent-to-agent signal.
     *
     * Default is a no-op. Override when the page owns a specific agent signal
     * workflow while the agent remains the process/truth-source boundary.
     *
     * @param AgentSignalData $data Wrapped signal payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
    }

    /**
     * Handle a routed cron signal.
     *
     * Default is a no-op. Override when a page owns the scheduled workflow.
     *
     * @param SignalDataInterface $data Cron payload
     * @param string $source Signal source
     * @param string $name Cron job name
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
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

    /**
     * Builds table mutation payloads for tables declared as affected by the event.
     *
     * @param string $eventKey Logical project event name used by SignalRouter table routes
     * @param SourceChange $change DB/RT source change to project into routed table mutations
     * @return TableMutationSignalCollection Table mutation payloads for immediate or pending fan-out
     */
    protected function buildTableMutationSignalsForSourceEvent(
        string $eventKey,
        SourceChange $change,
    ): TableMutationSignalCollection {
        if (Hilos::$table === null || Hilos::$sr === null) {
            return new TableMutationSignalCollection();
        }

        return Hilos::$table->buildMutationSignalsForSourceEvent(
            $change,
            Hilos::$sr->getTableKeysForEvent($eventKey),
        );
    }
}
