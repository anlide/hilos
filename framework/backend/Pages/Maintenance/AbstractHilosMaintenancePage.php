<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance;

use DateTimeImmutable;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Maintenance\DTO\HilosMaintenanceStateSignalData;
use Hilos\Pages\Maintenance\DTO\MaintenanceDisableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenancePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenancePassReplyDTO;
use Hilos\ProtectedMode\DTO\ManualMaintenanceDisableSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenanceEnableSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassSignalData;
use Hilos\ProtectedMode\DTO\ManualMaintenancePassDoneSignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Utils\Helpers\UtcMoment;
use Hilos\WiringRefusal;
use Throwable;

/**
 * AbstractHilosMaintenancePage - Abstract base for the Hilos maintenance section (HIL-1119).
 *
 * The first admin section with no on-switch: the freeze under it is core and unconditional, so a
 * project activates the section by registering its page and binding {@see HilosVerifierCircleTable}
 * to it in the topology, with no line in FEATURES (docs/agents/architecture/admin-features.md).
 *
 * The page hands manual entry to the index agent with a deferred answer (HIL-1362). It sends
 * the current protected-mode state before the page response, then pushes changes while open.
 * Circle edits belong to AbstractHilosMaintenanceCirclePage. The hilos index agent
 * ({@see AbstractHilosIndexAgent}) serves the page, owns the circle and marks its state stale on RT sync.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Maintenance\MaintenancePage).
 */
abstract class AbstractHilosMaintenancePage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_MAINTENANCE;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::MAINTENANCE_ENABLE => MaintenanceEnableActionDTO::class,
        HilosSignalConstants::MAINTENANCE_DISABLE => MaintenanceDisableActionDTO::class,
        HilosSignalConstants::MAINTENANCE_PASS => MaintenancePassActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::MAINTENANCE_ENABLE_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::MAINTENANCE_DISABLE_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::MAINTENANCE_PASS_DONE => ManualMaintenancePassDoneSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE,
    ];

    /** @var array<string, true> Connections currently subscribed to the Maintenance section */
    private static array $subscribers = [];

    /** Fingerprint of the last state broadcast, unaffected by individual subscriptions. */
    private static ?string $lastBroadcastFingerprint = null;

    /** Whether an RT sync has made the shown state potentially stale. */
    private static bool $stale = false;

    /** Marks the section for a push on the next index-agent tick when it has an audience. */
    public static function markStale(): void
    {
        if (self::$subscribers !== []) {
            self::$stale = true;
        }
    }

    /**
     * Forgets a departed connection; safe after both unsubscribe and connection close.
     *
     * @param string $acceptKey Connection accept key
     */
    public static function removeSubscriber(string $acceptKey): void
    {
        unset(self::$subscribers[$acceptKey]);
        if (self::$subscribers === []) {
            self::$lastBroadcastFingerprint = null;
            self::$stale = false;
        }
    }

    /** Clears worker-local subscribers when the index agent stops. */
    public static function reset(): void
    {
        self::$subscribers = [];
        self::$lastBroadcastFingerprint = null;
        self::$stale = false;
    }

    /**
     * Sends a changed state to admitted subscribers after an RT sync.
     *
     * A missing runtime row means this process holds no state yet; it cannot claim the node is
     * open, and the stale mark stays for the next tick. A locked-out connection receives nothing.
     *
     * @param PageAgentInterface $agent Index agent routing the page signal
     * @throws InvalidArgumentException When the subscription signal cannot be named or queued
     */
    public static function onAgentTick(PageAgentInterface $agent): void
    {
        if (self::$subscribers === [] || !self::$stale) {
            return;
        }

        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($row === null) {
            return;
        }

        $data = self::stateFromRow($row);
        self::$stale = false;
        $fingerprint = hash('sha256', serialize($data->toArray()));
        if ($fingerprint === self::$lastBroadcastFingerprint) {
            return;
        }
        self::$lastBroadcastFingerprint = $fingerprint;

        foreach (array_keys(self::$subscribers) as $acceptKey) {
            if ($row->locksOut($acceptKey, self::subscriberSessionTokenHash($acceptKey))) {
                continue;
            }

            $frame = static::frameForViewer($acceptKey, $data, HilosMaintenanceStateSignalData::wireFields());
            if ($frame === null) {
                continue;
            }

            Hilos::$sr->queueSignal(
                signalSource: $agent->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE),
                signalData: new WebSocketSignalData(data: $frame, targetAcceptKey: $acceptKey),
            );
        }
    }

    /**
     * Sends the state ahead of the page response that releases the first render.
     *
     * An individual answer does not touch the fingerprint or staleness of a pending broadcast.
     *
     * @param string $acceptKey Connection accept key
     * @param PageRouteParams $params Route parameters (unused)
     * @throws InvalidArgumentException When the subscription signal cannot be named or queued
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($row === null || $row->locksOut($acceptKey, self::subscriberSessionTokenHash($acceptKey))) {
            return;
        }

        $frame = static::frameForViewer($acceptKey, self::stateFromRow($row), HilosMaintenanceStateSignalData::wireFields());
        if ($frame !== null) {
            $this->sendToUser(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE, $acceptKey, $frame);
        }
    }

    /**
     * Registers a connection only after its page response succeeds.
     *
     * @param string $acceptKey Connection accept key
     * @param PageRouteParams $params Route parameters (unused)
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        self::$subscribers[$acceptKey] = true;
    }

    /**
     * @param string $acceptKey Connection leaving the section
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        self::removeSubscriber($acceptKey);
    }

    /**
     * @param ProtectedModeRuntime $row Current protected-mode row
     * @return HilosMaintenanceStateSignalData State shown in the section
     */
    private static function stateFromRow(ProtectedModeRuntime $row): HilosMaintenanceStateSignalData
    {
        return new HilosMaintenanceStateSignalData(
            phase: $row->phase,
            operation: $row->operation,
            manual: $row->isManualMaintenanceWindow(),
            startedAt: $row->startedAt === null ? null : UtcMoment::format(new DateTimeImmutable('@' . $row->startedAt)),
            enteredFromBrowser: $row->enteredFromBrowser,
            passCount: count($row->passHashes),
            circleNamed: $row->circleNamedCount,
            circleAdmitted: count($row->circleSessionTokenHashes),
        );
    }

    /**
     * Routes manual entry/exit and pass requests; handovers answer after the index agent reports its outcome.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the deferred acknowledgment carries the outcome
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When manual-window admission is refused or the browser has no session
     * @throws InvalidArgumentException When the maintenance request cannot be named or queued
     * @throws InvalidFormatException When the initiating browser identity is empty
     * @throws HilosException When the maintenance request fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::MAINTENANCE_ENABLE:
                if (!$dto instanceof MaintenanceEnableActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenanceEnableActionDTO::class, $dto);
                }
                $this->handleEnable($acceptKey);

                break;

            case HilosSignalConstants::MAINTENANCE_DISABLE:
                if (!$dto instanceof MaintenanceDisableActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenanceDisableActionDTO::class, $dto);
                }
                $this->handleDisable($acceptKey);

                break;

            case HilosSignalConstants::MAINTENANCE_PASS:
                if (!$dto instanceof MaintenancePassActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenancePassActionDTO::class, $dto);
                }
                $this->handlePass($acceptKey);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Answers the initiating submit even when this page was recreated during admission to the window.
     *
     * @param AgentSignalData $data Outcome hydrated by the page signal dispatcher
     * @param string $sender Sending agent identity (unused)
     * @param string $name Page-owned signal name
     * @throws AgentUnknownSignalException When the signal is not owned by this page
     * @throws InvalidArgumentException When the action answer cannot be named
     * @throws InvalidFormatException When a successful outcome contains an empty code
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::MAINTENANCE_ENABLE_DONE:
            case HilosSignalConstants::MAINTENANCE_DISABLE_DONE:
                $this->answerHandover($data->data);

                break;

            case HilosSignalConstants::MAINTENANCE_PASS_DONE:
                $this->handlePassDone($data->data);

                break;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param string $acceptKey Initiating browser connection
     * @throws TableActionException When the connection carries no session
     * @throws InvalidArgumentException When the request cannot be named or queued
     * @throws InvalidFormatException When the browser identity is empty
     */
    private function handleEnable(string $acceptKey): void
    {
        $sessionHash = self::subscriberSessionTokenHash($acceptKey);
        if ($sessionHash === null) {
            throw new TableActionException('A browser session is required to turn on maintenance');
        }

        $this->forward(
            HilosSignalConstants::MAINTENANCE_AGENT_ENABLE,
            new ManualMaintenanceEnableSignalData(
                acceptKey: $acceptKey,
                sessionHash: $sessionHash,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::MAINTENANCE_ENABLE,
                replySignal: HilosSignalConstants::MAINTENANCE_ENABLE_DONE,
                successMessage: 'Maintenance is on — the system is closed to visitors',
            ),
        );
    }

    /**
     * Null runtime state means this process cannot admit an exit request.
     *
     * @param string $acceptKey Acting browser connection
     * @throws TableActionException When the browser has no session or is outside the manual window
     * @throws InvalidArgumentException When the request cannot be named or queued
     * @throws InvalidFormatException When the browser identity is empty
     */
    private function handleDisable(string $acceptKey): void
    {
        $sessionHash = self::subscriberSessionTokenHash($acceptKey);
        if ($sessionHash === null) {
            throw new TableActionException('A browser session is required to turn off maintenance');
        }

        if (Hilos::$rt?->hilosProtectedModeRuntime === null) {
            throw new TableActionException('Protected-mode state is unavailable');
        }

        if (
            Hilos::$rt->hilosProtectedModeRuntime->phase !== StateProtectedModeRuntime::PHASE_VERIFYING
            || !Hilos::$rt->hilosProtectedModeRuntime->isManualMaintenanceWindow()
        ) {
            throw new TableActionException('The current window is not manual maintenance');
        }

        if (Hilos::$rt->hilosProtectedModeRuntime->locksOut($acceptKey, $sessionHash)) {
            throw new TableActionException('This browser session is not admitted to maintenance');
        }

        $this->forward(
            HilosSignalConstants::MAINTENANCE_AGENT_DISABLE,
            new ManualMaintenanceDisableSignalData(
                acceptKey: $acceptKey,
                sessionHash: $sessionHash,
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::MAINTENANCE_DISABLE,
                replySignal: HilosSignalConstants::MAINTENANCE_DISABLE_DONE,
                successMessage: 'Maintenance is off — pages are reloading',
            ),
        );
    }

    /**
     * @param string $acceptKey Acting browser connection
     * @throws TableActionException When the request is untracked or the browser is outside the manual window
     * @throws InvalidArgumentException When the request cannot be named or queued
     * @throws InvalidFormatException When the browser identity or correlation is empty
     */
    private function handlePass(string $acceptKey): void
    {
        if ($this->currentActionRequestId() === null || $this->currentActionRequestId() === '') {
            throw new TableActionException('Issuing a code requires a tracked request');
        }

        $this->forward(
            HilosSignalConstants::MAINTENANCE_AGENT_PASS,
            new ManualMaintenancePassSignalData(
                acceptKey: $acceptKey,
                sessionHash: $this->assertPassAdmission($acceptKey),
                requestId: $this->currentActionRequestId(),
                action: HilosSignalConstants::MAINTENANCE_PASS,
                replySignal: HilosSignalConstants::MAINTENANCE_PASS_DONE,
                successMessage: 'Code issued',
            ),
        );
    }

    /**
     * Null runtime state means this process cannot admit a code request or disclose its result.
     *
     * @param string $acceptKey Acting browser connection
     * @return string Current server-resolved session hash
     * @throws TableActionException When the session or manual window is missing or admission is lost
     */
    private function assertPassAdmission(string $acceptKey): string
    {
        $sessionHash = self::subscriberSessionTokenHash($acceptKey);
        if ($sessionHash === null) {
            throw new TableActionException('A browser session is required to issue a code');
        }

        if (Hilos::$rt?->hilosProtectedModeRuntime === null) {
            throw new TableActionException('Protected-mode state is unavailable');
        }

        if (
            Hilos::$rt->hilosProtectedModeRuntime->phase !== StateProtectedModeRuntime::PHASE_VERIFYING
            || !Hilos::$rt->hilosProtectedModeRuntime->isManualMaintenanceWindow()
        ) {
            throw new TableActionException('The current window is not manual maintenance');
        }

        if (Hilos::$rt->hilosProtectedModeRuntime->locksOut($acceptKey, $sessionHash)) {
            throw new TableActionException('This browser session is not admitted to maintenance');
        }

        return $sessionHash;
    }

    /**
     * Rechecks current access before disclosing a code to the originating tracked submit.
     *
     * @param ManualMaintenancePassDoneSignalData $done Addressed outcome from the owner
     * @throws InvalidArgumentException When the answer cannot be named
     * @throws InvalidFormatException When a successful outcome contains an empty code
     */
    private function handlePassDone(ManualMaintenancePassDoneSignalData $done): void
    {
        if ($done->answer->error !== null) {
            $this->answerHandover($done->answer);

            return;
        }

        try {
            PageAccessGate::assertAction(static::class, $done->answer->acceptKey, HilosSignalConstants::MAINTENANCE_PASS);
            $this->assertPassAdmission($done->answer->acceptKey);
        } catch (WiringRefusal $e) {
            // The waiting browser receives the wiring failure without the code.
            $this->refusePassDisclosure($done->answer, ActionRefusal::fromThrowable($e));

            return;
        } catch (Throwable $e) {
            $this->refusePassDisclosure($done->answer, ActionRefusal::fromThrowable($e));

            return;
        }

        if ($done->answer->successMessage !== null) {
            $this->setActionSuccessMessage($done->answer->successMessage);
        }
        $this->sendActionSuccess(
            $done->answer->acceptKey,
            $done->answer->action,
            $done->answer->requestId,
            new MaintenancePassReplyDTO($done->pass),
        );
    }

    /**
     * @param HandoverAnswerSignalData $answer Original correlation
     * @param ActionRefusal $refusal Current admission refusal
     * @throws InvalidArgumentException When the refusal cannot be named
     */
    private function refusePassDisclosure(HandoverAnswerSignalData $answer, ActionRefusal $refusal): void
    {
        $this->answerHandover(new HandoverAnswerSignalData(
            $answer->acceptKey,
            $answer->requestId,
            $answer->action,
            null,
            $refusal->reason,
            $refusal->errorType,
            $refusal->errorDetail,
        ));
    }

}
