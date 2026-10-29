<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance;

use DateTimeImmutable;
use Hilos\Backup\Agent\BackupAgent;
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
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageAccountFrozenException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
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
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreCloseActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreOpenActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestorePassActionDTO;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlDoneSignalData;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlSignalData;
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
        HilosSignalConstants::MAINTENANCE_RESTORE_PASS => MaintenanceRestorePassActionDTO::class,
        HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => MaintenanceRestoreOpenActionDTO::class,
        HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => MaintenanceRestoreCloseActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::MAINTENANCE_ENABLE_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::MAINTENANCE_DISABLE_DONE => HandoverAnswerSignalData::class,
            HilosSignalConstants::MAINTENANCE_PASS_DONE => ManualMaintenancePassDoneSignalData::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE => MaintenanceRestoreControlDoneSignalData::class,
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
        $fingerprint = self::broadcastFingerprint($row, $data);
        if ($fingerprint === self::$lastBroadcastFingerprint) {
            return;
        }
        self::$lastBroadcastFingerprint = $fingerprint;

        foreach (array_keys(self::$subscribers) as $acceptKey) {
            $sessionHash = self::subscriberSessionTokenHash($acceptKey);
            if ($row->locksOut($acceptKey, $sessionHash)) {
                continue;
            }

            $frame = static::frameForViewer(
                $acceptKey,
                $data->withIsInitiator($row->belongsToInitiator($sessionHash)),
                HilosMaintenanceStateSignalData::wireFields(),
            );
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
        $sessionHash = self::subscriberSessionTokenHash($acceptKey);
        if ($row === null || $row->locksOut($acceptKey, $sessionHash)) {
            return;
        }

        $frame = static::frameForViewer(
            $acceptKey,
            self::stateFromRow($row)->withIsInitiator($row->belongsToInitiator($sessionHash)),
            HilosMaintenanceStateSignalData::wireFields(),
        );
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
     * Shared frame for every subscriber. The initiator flag stays false here and is
     * filled in per accept key after the admission check.
     *
     * @param ProtectedModeRuntime $row Current protected-mode row
     * @return HilosMaintenanceStateSignalData State shown in the section, before the personal flag
     */
    private static function stateFromRow(ProtectedModeRuntime $row): HilosMaintenanceStateSignalData
    {
        return new HilosMaintenanceStateSignalData(
            phase: $row->phase,
            operation: $row->operation,
            manual: $row->isManualMaintenanceWindow(),
            startedAt: self::formatEpoch($row->startedAt),
            enteredFromBrowser: $row->enteredFromBrowser,
            passCount: count($row->passHashes),
            circleNamed: $row->circleNamedCount,
            circleAdmitted: count($row->circleSessionTokenHashes),
            initiatorAgentType: $row->initiatorAgentType,
            initiatorNodeId: $row->initiatorNodeId,
            activatedAt: self::formatEpoch($row->activatedAt),
            progressAt: self::formatEpoch($row->progressAt),
            isInitiator: false,
            diagnosticsNodeId: $row->diagnosticsNodeId,
            stoppedAgentCount: $row->stoppedAgentCount,
            preservedAgentTypes: $row->preservedAgentTypes,
            agentStartGateClosed: $row->agentStartGateClosed,
            watchdogVerdict: $row->watchdogVerdict,
            watchdogPendingNodeIds: $row->watchdogPendingNodeIds,
            watchdogQuiesceTimeoutSeconds: $row->watchdogQuiesceTimeoutSeconds,
            watchdogSilenceTimeoutSeconds: $row->watchdogSilenceTimeoutSeconds,
            watchdogAlertIntervalSeconds: $row->watchdogAlertIntervalSeconds,
            watchdogAlertEmails: $row->watchdogAlertEmails,
            watchdogManualRestartIsNormal: $row->watchdogManualRestartIsNormal,
        );
    }

    /**
     * @param ?int $epochSeconds Epoch seconds on the runtime row, or null when the moment is absent
     * @return ?string ISO-8601 UTC with Z, or null when the row has no moment
     */
    private static function formatEpoch(?int $epochSeconds): ?string
    {
        return $epochSeconds === null
            ? null
            : UtcMoment::format(new DateTimeImmutable('@' . $epochSeconds));
    }

    /**
     * Shared facts, salted by who the initiator is, so a change of that session is broadcast.
     *
     * The salt is the row's session hash. It is not a field of the frame.
     *
     * @param ProtectedModeRuntime $row Current protected-mode row
     * @param HilosMaintenanceStateSignalData $state Shared frame, before any viewer's flag
     * @return string Fingerprint compared with the last broadcast
     */
    private static function broadcastFingerprint(
        ProtectedModeRuntime $row,
        HilosMaintenanceStateSignalData $state,
    ): string {
        return hash('sha256', serialize([$state->toArray(), $row->initiatorSessionTokenHash]));
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

            case HilosSignalConstants::MAINTENANCE_RESTORE_PASS:
                if (!$dto instanceof MaintenanceRestorePassActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenanceRestorePassActionDTO::class, $dto);
                }
                $this->handleRestoreControl(
                    $acceptKey,
                    $action,
                    MaintenanceRestoreControlSignalData::CONTROL_PASS,
                    'Code issued',
                );

                break;

            case HilosSignalConstants::MAINTENANCE_RESTORE_OPEN:
                if (!$dto instanceof MaintenanceRestoreOpenActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenanceRestoreOpenActionDTO::class, $dto);
                }
                $this->handleRestoreControl(
                    $acceptKey,
                    $action,
                    MaintenanceRestoreControlSignalData::CONTROL_OPEN,
                    'The system is open',
                );

                break;

            case HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE:
                if (!$dto instanceof MaintenanceRestoreCloseActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenanceRestoreCloseActionDTO::class, $dto);
                }
                $this->handleRestoreControl(
                    $acceptKey,
                    $action,
                    MaintenanceRestoreControlSignalData::CONTROL_CLOSE,
                    'The window is closed again',
                );

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

            case HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE:
                $this->handleRestoreControlDone($data->data);

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

    /**
     * Hands one restore control to the backup agent after the server has resolved who is asking.
     *
     * The browser sends an empty payload. Identity, the restore window and the sentence travel in
     * the ask. A refusal here is the action's own answer: nothing is deferred and no ask is sent.
     *
     * @param string $acceptKey Acting browser connection
     * @param string $action Browser action being forwarded
     * @param string $control pass, open or close
     * @param string $successMessage Sentence spoken only after the control is confirmed
     * @throws TableActionException When the request is untracked or the browser is outside the restore window
     * @throws PageUnauthorizedException When the connection has no account
     * @throws PageAccountFrozenException When the person is frozen
     * @throws PageForbiddenException When the connection is not an administrator
     * @throws ActionViewModeException When the connection may only look
     * @throws HilosException When the administrator lookup fails
     * @throws InvalidArgumentException When the ask cannot be named or queued
     * @throws InvalidFormatException When the ask's identity or correlation is empty
     */
    private function handleRestoreControl(
        string $acceptKey,
        string $action,
        string $control,
        string $successMessage,
    ): void {
        if ($this->currentActionRequestId() === null || $this->currentActionRequestId() === '') {
            throw new TableActionException('A restore control requires a tracked request');
        }

        PageAccessGate::assertAction(static::class, $acceptKey, $action);
        $sessionHash = $this->assertRestoreAdmission($acceptKey);
        $this->forward(
            HilosSignalConstants::MAINTENANCE_AGENT_RESTORE_CONTROL,
            new MaintenanceRestoreControlSignalData(
                control: $control,
                acceptKey: $acceptKey,
                sessionHash: $sessionHash,
                requestId: $this->currentActionRequestId(),
                action: $action,
                replySignal: HilosSignalConstants::MAINTENANCE_RESTORE_CONTROL_DONE,
                successMessage: $successMessage,
            ),
        );
    }

    /**
     * Admits the current browser to drive the restore window it started.
     *
     * The same user id is not an admission: the session hash on the row is the initiator, and a
     * second browser of that person is refused here, before any ask exists.
     *
     * @param string $acceptKey Acting browser connection
     * @return string Current server-resolved session hash
     * @throws TableActionException When the session, the row or the restore window does not admit this browser
     */
    private function assertRestoreAdmission(string $acceptKey): string
    {
        $sessionHash = self::subscriberSessionTokenHash($acceptKey);
        if ($sessionHash === null) {
            throw new TableActionException('A browser session is required to control a restore');
        }

        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($row === null) {
            throw new TableActionException('Protected-mode state is unavailable');
        }

        if ($row->isManualMaintenanceWindow()) {
            throw new TableActionException('The current window is manual maintenance');
        }

        if ($row->operation !== BackupAgent::RESTORE_OPERATION) {
            throw new TableActionException('The current window is not a restore');
        }

        if ($row->phase !== StateProtectedModeRuntime::PHASE_VERIFYING) {
            throw new TableActionException('The current window is not verifying');
        }

        if (!$row->belongsToInitiator($sessionHash)) {
            throw new TableActionException('This browser session did not start the restore');
        }

        if ($row->locksOut($acceptKey, $sessionHash)) {
            throw new TableActionException('This browser session is not admitted to maintenance');
        }

        return $sessionHash;
    }

    /**
     * Discloses a restore code only after a second look, and answers open or close with no domain reply.
     *
     * A pass whose window no longer admits this browser is refused without the code. Open and close
     * carry nothing to disclose: their success is the handover sentence alone.
     *
     * @param MaintenanceRestoreControlDoneSignalData $done Addressed outcome from the backup agent
     * @throws InvalidArgumentException When the answer cannot be named
     * @throws InvalidFormatException When a successful pass contains an empty code
     */
    private function handleRestoreControlDone(MaintenanceRestoreControlDoneSignalData $done): void
    {
        if ($done->answer->error !== null || $done->answer->action !== HilosSignalConstants::MAINTENANCE_RESTORE_PASS) {
            $this->answerHandover($done->answer);

            return;
        }

        try {
            PageAccessGate::assertAction(
                static::class,
                $done->answer->acceptKey,
                HilosSignalConstants::MAINTENANCE_RESTORE_PASS,
            );
            $this->assertRestoreAdmission($done->answer->acceptKey);
        } catch (WiringRefusal $e) {
            // The waiting browser receives the wiring failure without the code.
            $this->refusePassDisclosure($done->answer, ActionRefusal::fromThrowable($e));

            return;
        } catch (Throwable $e) {
            $this->refusePassDisclosure($done->answer, ActionRefusal::fromThrowable($e));

            return;
        }

        $pass = $done->pass;
        if ($pass === null || $pass === '') {
            throw new InvalidFormatException('A successful restore pass requires a code and a tracked request');
        }

        if ($done->answer->successMessage !== null) {
            $this->setActionSuccessMessage($done->answer->successMessage);
        }
        $this->sendActionSuccess(
            $done->answer->acceptKey,
            $done->answer->action,
            $done->answer->requestId,
            new MaintenancePassReplyDTO($pass),
        );
    }

}
