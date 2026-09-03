<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndex;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\DTO\LogsTakeoutConfirmSignalData;
use Hilos\Log\DTO\LogsTakeoutUndoSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\LogSettingsResolver;
use Hilos\Pages\Logs\DTO\HilosLogsRotationsSignalData;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmActionDTO;
use Hilos\Pages\Logs\DTO\LogsTakeoutUndoActionDTO;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use JsonException;

/**
 * Abstract Hilos admin page: the history of archived log rotation batches.
 *
 * Two things travel to this screen and they travel apart. The header — whether there is a picture,
 * which nodes it holds, and the rules in force — is this page's own signal, sent once on subscribe
 * and again whenever it changes. The batches ride the ordinary windowed table
 * ({@see HilosLogRotationsTable}) over `table_viewport` → `table_window`, so scrolling and sorting
 * cost a window rather than a header.
 *
 * Like the overview ({@see AbstractHilosLogsPage}), every connection that subscribes is also
 * counted as a viewer of the SECTION: without that the aggregator sends nothing, the mirror stays
 * empty, and this screen would be permanently empty rather than merely new.
 *
 * The window is re-sent and not patched. A `table_viewport_delta` is built from a
 * {@see SourceChange}, which is a DB or runtime event; the mirror is neither and
 * raises none, so there is nothing a delta could be made of. Re-sending costs nothing under the
 * hand either — batches change once per rotation, and each connection's own descriptor (its sort,
 * filter and page) is what the window is rebuilt from, so an administrator stays where they stood.
 *
 * Both actions of this screen — an operator's word that a batch has been carried off (HIL-483),
 * and the same word taken back while the batch is still there (HIL-759) — are handed on rather
 * than answered. The directory belongs to one node's {@see LogStoreAgent}, and this page runs
 * wherever the browser happens to be attached, so it checks that the node the request names is
 * still there, sends the request to that node's owner and steps out of its own ack. The row the
 * operator is looking at is repainted by the node's next index reaching the mirror, not by the
 * ack — which is why {@see self::rowsFingerprint()} counts the takeout stamp among the things a
 * window is rebuilt for.
 *
 * Projects register a concrete empty subclass in the page factory (wiring only).
 */
abstract class AbstractHilosLogsRotationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_ROTATIONS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
    ];

    public const array ACTIONS = [
        HilosSignalConstants::LOGS_TAKEOUT_CONFIRM => LogsTakeoutConfirmActionDTO::class,
        HilosSignalConstants::LOGS_TAKEOUT_UNDO => LogsTakeoutUndoActionDTO::class,
    ];

    /** Seconds between two tick refreshes, so a busy agent does not rebuild the header per loop pass. */
    private const float REFRESH_THROTTLE_SECONDS = 0.1;

    /** @var array<string, true> WebSocket accept keys currently subscribed to this page */
    private static array $subscribers = [];

    /** @var ?float Wall clock ({@see microtime()}) of the last tick refresh, null before the first */
    private static ?float $lastRefreshAt = null;

    /**
     * @var string Fingerprint of what the batches were built from when the windows were last sent
     *
     * Of the sources and not of a window: a window is per connection (its own sort, filter and
     * page), while the question "is there anything new to show" is one question for everybody.
     */
    private static string $lastRowsFingerprint = '';

    /** @var string Fingerprint of the header payload last sent, so an unchanged header is not re-sent */
    private static string $lastHeaderFingerprint = '';

    /**
     * @var ?LogSettingsResolver The reader the rules in the header come from, one per process
     *
     * Kept between ticks the way {@see LogStoreAgent} keeps its own: the resolver
     * remembers the last outcome so an unchanged fault stops repeating itself into the journal.
     */
    private static ?LogSettingsResolver $settingsResolver = null;

    /**
     * Removes a connection from the subscriber set and stops counting it as a viewer.
     *
     * Called from {@see AbstractHilosLogsAgent::onSignalConnectionClose()}, which is the path a
     * connection that went without a word arrives by — so the section's viewer count is dropped
     * here too, and not only on the orderly unsubscribe. Left counted, such a viewer would keep the
     * aggregator sending frames for a page nobody has open.
     *
     * @param string $acceptKey Target connection accept key
     */
    public static function removeSubscriber(string $acceptKey): void
    {
        unset(self::$subscribers[$acceptKey]);
        ClusterLogIndexMirror::removeViewer($acceptKey);
    }

    /**
     * Worker tick hook for the Hilos logs agent: throttled change check, then a push on change.
     *
     * A changed picture re-sends every subscriber's window, because the rows come out of it; a
     * changed header goes out in the same pass. The rules count as part of the picture for the
     * first purpose too — the retention verdict of every row is judged from them, so an edited
     * threshold repaints the badges without the batches themselves having moved.
     *
     * @param PageAgentInterface $agent Hilos logs agent, for {@see PageAgentInterface::getAgentSignalSource()}
     * @throws InvalidArgumentException When the header or the table-window signal cannot be named
     * @throws TableRowKeyMissingException When a windowed row is a placeholder and carries no key
     */
    public static function onAgentTick(PageAgentInterface $agent): void
    {
        if (self::$subscribers === []) {
            return;
        }

        $now = microtime(true);
        if (self::$lastRefreshAt !== null && ($now - self::$lastRefreshAt) < self::REFRESH_THROTTLE_SECONDS) {
            return;
        }
        self::$lastRefreshAt = $now;

        $header = self::buildHeader();
        $headerFingerprint = self::fingerprintOf($header->toArray());
        $rowsFingerprint = self::rowsFingerprint($header);

        if ($rowsFingerprint !== self::$lastRowsFingerprint) {
            self::$lastRowsFingerprint = $rowsFingerprint;
            self::resendWindows();
        }

        if ($headerFingerprint === self::$lastHeaderFingerprint) {
            return;
        }
        self::$lastHeaderFingerprint = $headerFingerprint;
        foreach (array_keys(self::$subscribers) as $acceptKey) {
            self::queueHeaderToUser($agent, $acceptKey, $header);
        }
    }

    /**
     * Routes the two actions of this screen: the word that a batch has been carried off, and its
     * withdrawal.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @return ?ActionReplyDTO Always null: both are acked by the node that owns the directory
     * @throws AgentUnknownActionException When the page does not support the action
     * @throws InvalidActionPayloadException When the payload is not the request its action declares
     * @throws TableActionException When the request names a node this cluster does not have, or one
     *     the master last saw offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::LOGS_TAKEOUT_CONFIRM:
                if (!$dto instanceof LogsTakeoutConfirmActionDTO) {
                    throw new InvalidActionPayloadException($action, LogsTakeoutConfirmActionDTO::class, $dto);
                }

                return $this->forwardTakeoutConfirm($acceptKey, $action, $dto);

            case HilosSignalConstants::LOGS_TAKEOUT_UNDO:
                if (!$dto instanceof LogsTakeoutUndoActionDTO) {
                    throw new InvalidActionPayloadException($action, LogsTakeoutUndoActionDTO::class, $dto);
                }

                return $this->forwardTakeoutUndo($acceptKey, $action, $dto);

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Drops subscriber state when the client leaves this page.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        $this->logAgentInfo("hilos_logs_rotations onUnsubscribe acceptKey={$acceptKey}");
        self::removeSubscriber($acceptKey);
    }

    /**
     * Sends the screen header over the WebSocket, ahead of the page_response frame.
     *
     * The header rides a signal of its own and must reach the client before the frame that releases
     * the page, because that frame means the subscription is answered in full.
     *
     * The two fingerprints are deliberately NOT moved here. They say what the last BROADCAST
     * carried, and they are one pair for the whole process: a newcomer marking the current state as
     * already sent would cancel the push the throttled tick still owes everybody who was here
     * before them. The cost of leaving them is one duplicate frame to the newcomer on the next
     * change; the cost of moving them is an admin left looking at a picture that moved.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused for this page)
     * @throws InvalidArgumentException When the header signal cannot be named
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->logAgentInfo("hilos_logs_rotations onSubscribe acceptKey={$acceptKey}");

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
            $acceptKey,
            self::buildHeader(),
        );
    }

    /**
     * Registers the connection for the live pushes, and counts it as a viewer of the section.
     *
     * Both run after the answer so a subscription that was refused leaves neither a subscriber nor
     * a viewer behind: a viewer counted for a page nobody was given would keep the aggregator
     * sending frames for as long as that socket lived.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused for this page)
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        self::$subscribers[$acceptKey] = true;
        ClusterLogIndexMirror::addViewer($acceptKey);
    }

    /**
     * Builds the header out of the cluster picture and the rules in force.
     *
     * @return HilosLogsRotationsSignalData Header payload
     */
    private static function buildHeader(): HilosLogsRotationsSignalData
    {
        $index = ClusterLogIndexMirror::index();
        $resolver = self::settingsResolver();
        // The complaint is not taken: the rotation agent on each node already writes that line,
        // and a second copy from the page worker says nothing new.
        $rotation = $resolver->rotationPolicy();
        $retention = $resolver->retentionPolicy();

        return new HilosLogsRotationsSignalData(
            available: self::availabilityOf($index),
            nodes: self::namedNodesOf($index),
            rotationCron: $rotation->cronExpression,
            rotationMaxAgeSeconds: $rotation->maxAgeSeconds,
            rotationMaxLiveSizeBytes: $rotation->maxLiveSizeBytes,
            retentionKeepBatches: $retention->keepBatches,
            retentionMaxAgeSeconds: $retention->maxAgeSeconds,
        );
    }

    /**
     * The three answers to "is there anything to draw".
     *
     * A picture that holds no node is the same answer as no picture at all — nobody has told us
     * anything — and reporting it as false would claim every log store in the cluster was read and
     * found unreadable.
     *
     * @param ?ClusterLogIndex $index Cluster picture, or null while none has arrived
     * @return ?bool True when there are batches to list, false when no node can read its store,
     *     null while nobody has reported
     */
    private static function availabilityOf(?ClusterLogIndex $index): ?bool
    {
        $slots = $index?->nodes() ?? [];
        if ($slots === []) {
            return null;
        }

        foreach ($slots as $slot) {
            if ($slot->index->available) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nodes the filter may offer, which are the ones that have a name.
     *
     * An installation with no node id at all reports under no name, and an empty list is how the
     * screen is told to drop its node column and node filter rather than offer a choice of one.
     *
     * @param ?ClusterLogIndex $index Cluster picture, or null while none has arrived
     * @return list<string> Node names, in the order the picture holds them
     */
    private static function namedNodesOf(?ClusterLogIndex $index): array
    {
        $nodes = [];
        foreach ($index?->nodes() ?? [] as $slot) {
            if ($slot->nodeId !== null) {
                $nodes[] = $slot->nodeId;
            }
        }

        return $nodes;
    }

    /**
     * Re-serves every subscriber's own window of the rotation table.
     *
     * A connection that has not asked for a window yet is skipped rather than served a default
     * one: the descriptor is the client's, and inventing one would send rows nobody is showing.
     *
     * @throws TableRowKeyMissingException When a windowed row is a placeholder and carries no key
     * @throws InvalidArgumentException When the table-window signal cannot be named
     */
    private static function resendWindows(): void
    {
        foreach (array_keys(self::$subscribers) as $acceptKey) {
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosLogRotationsTable::TABLE);
            if ($viewport === null) {
                continue;
            }

            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_LOGS_ROTATIONS, $acceptKey, $viewport);
        }
    }

    /**
     * Fingerprint of everything the batch rows are projected from — the picture AND the rules.
     *
     * The batch figures rather than the frame's arrival time: a node re-sends its index whole on
     * its own schedule, so an arrival time would move on every frame and re-send every window for
     * a picture that had not changed at all. The rules belong here as well as in the header,
     * because the retention badge of every row is judged from them: an edited threshold repaints
     * the column without a single batch having moved.
     *
     * The takeout stamp, the node's log root and its undo window are counted for the same reason
     * and would be easy to leave out, because none of them is a measurement of the archive. A
     * confirmation — or its withdrawal — moves nothing else about a batch, so without the stamp
     * here the click an operator just made would reach the mirror and stop, leaving them looking
     * at the badge they had before; the log root is where every row's absolute address comes from,
     * and the window is what every row's prune deadline is computed with
     * ({@see HilosLogRotationsTable}).
     *
     * @param HilosLogsRotationsSignalData $header Header of this pass, for the rules it carries
     * @return string Stable fingerprint of what the rows are built from
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    private static function rowsFingerprint(HilosLogsRotationsSignalData $header): string
    {
        $slots = [];
        foreach (ClusterLogIndexMirror::index()?->nodes() ?? [] as $slot) {
            $slots[] = [
                $slot->nodeId,
                $slot->index->available,
                $slot->index->logDirectory,
                $slot->index->takeoutUndoWindowSeconds,
                array_map(
                    static fn(LogBatchSummary $batch): array => [
                        $batch->timestamp,
                        $batch->agentFileCount,
                        $batch->agentBytes,
                        $batch->workerFileCount,
                        $batch->workerBytes,
                        $batch->workerMonopolisticFileCount,
                        $batch->workerMonopolisticBytes,
                        $batch->daemonFileCount,
                        $batch->daemonBytes,
                        $batch->takenAt,
                    ],
                    $slot->index->batches,
                ),
            ];
        }

        return self::fingerprintOf([$slots, $header->toArray()]);
    }

    /**
     * Stable JSON fingerprint of one value, for change detection between ticks.
     *
     * @param mixed $value Value to fingerprint
     * @return string JSON string
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    private static function fingerprintOf(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Queues a user-targeted WebSocket signal carrying the header.
     *
     * @param PageAgentInterface $agent Logs agent providing {@see PageAgentInterface::getAgentSignalSource()}
     * @param string $acceptKey Connection accept key
     * @param HilosLogsRotationsSignalData $header Payload
     * @throws InvalidArgumentException When the header signal cannot be named
     */
    private static function queueHeaderToUser(
        PageAgentInterface $agent,
        string $acceptKey,
        HilosLogsRotationsSignalData $header,
    ): void {
        Hilos::$sr?->queueSignal(
            signalSource: $agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS),
            signalData: new WebSocketSignalData(data: $header, targetAcceptKey: $acceptKey),
        );
    }

    /**
     * The process-wide settings reader the header's rules come from.
     *
     * @return LogSettingsResolver Reader over the settings, with the environment beneath them
     */
    private static function settingsResolver(): LogSettingsResolver
    {
        return self::$settingsResolver ??= new LogSettingsResolver();
    }

    /**
     * Hands one confirmation to the node that owns the batch directory and lets that node answer it.
     *
     * The id of whoever confirmed is added here and not sent by the browser: the marker records
     * who carried the batch off, and the owner of the directory has no session to ask — the socket
     * is attached to this page worker. A connection carrying no user leaves it null, which is the
     * honest answer for a fact nobody can be named for rather than a reason to refuse.
     *
     * @param string $acceptKey Accept key of the connection waiting for the confirmation
     * @param string $action Action name the reply acknowledges
     * @param LogsTakeoutConfirmActionDTO $dto Validated confirmation request
     * @return ?ActionReplyDTO Always null: the answer is owed by the owner, not by this page
     * @throws TableActionException When the request names a node this cluster does not have, or one
     *     the master last saw offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    private function forwardTakeoutConfirm(
        string $acceptKey,
        string $action,
        LogsTakeoutConfirmActionDTO $dto,
    ): ?ActionReplyDTO {
        $this->requireLiveNode($dto->nodeId);

        // Deferring is the promise that somebody else acks, so it is made only when there is an
        // ack to make: an untracked confirmation has nothing to correlate and the owner refuses to
        // write for it, the same shape the viewer page hands its reads over in.
        $requestId = $this->currentActionRequestId();
        $this->sendToAgent(
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM,
            LogsTakeoutConfirmSignalData::fromAction(
                $dto,
                $acceptKey,
                $action,
                $requestId,
                Hilos::$browser?->resolveActionUserId($acceptKey),
            ),
        );
        if ($requestId !== null) {
            $this->deferActionReply();
        }

        return null;
    }

    /**
     * Hands one withdrawal to the node that owns the batch directory and lets that node answer it.
     *
     * The twin of {@see self::forwardTakeoutConfirm()} minus the one field it fills in: the
     * confirmation records WHO said the batch was carried off, and taking that word back leaves no
     * fact behind to attribute, so there is nobody for this frame to name.
     *
     * @param string $acceptKey Accept key of the connection waiting for the withdrawal
     * @param string $action Action name the reply acknowledges
     * @param LogsTakeoutUndoActionDTO $dto Validated withdrawal request
     * @return ?ActionReplyDTO Always null: the answer is owed by the owner, not by this page
     * @throws TableActionException When the request names a node this cluster does not have, or one
     *     the master last saw offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     * @throws InvalidArgumentException When the frame to the owner cannot be named
     */
    private function forwardTakeoutUndo(
        string $acceptKey,
        string $action,
        LogsTakeoutUndoActionDTO $dto,
    ): ?ActionReplyDTO {
        $this->requireLiveNode($dto->nodeId);

        // Deferring is the promise that somebody else acks, so it is made only when there is an
        // ack to make: an untracked withdrawal has nothing to correlate and the owner refuses to
        // act for it, the same shape the confirmation is handed over in.
        $requestId = $this->currentActionRequestId();
        $this->sendToAgent(
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO,
            LogsTakeoutUndoSignalData::fromAction($dto, $acceptKey, $action, $requestId),
        );
        if ($requestId !== null) {
            $this->deferActionReply();
        }

        return null;
    }

    /**
     * Refuses a node the operator can still do something about, before the frame travels.
     *
     * The twin of {@see AbstractHilosLogsViewPage::requireLiveNode()}, and deliberately its own
     * copy: the two screens hand different requests to the same owners, and the check belongs to
     * the handover rather than to a shared ancestor neither page has.
     *
     * Two refusals and not one, because they mean opposite things to the person reading: an id
     * no node ever answered to is a stale or mistyped choice, while a node the master last saw
     * offline is the machine whose archive is being asked about.
     *
     * An empty id is this node and is not looked up at all — a single-node install publishes
     * itself under one, so a lookup would be asking whether this machine exists.
     *
     * @param string $nodeId Node id from the request, empty for this node
     * @throws TableActionException When no such node is known, or the master last saw it offline
     * @throws RtActionsStateCollectionNullException When the cluster roster is unavailable
     */
    private function requireLiveNode(string $nodeId): void
    {
        if ($nodeId === '') {
            return;
        }

        $node = Hilos::$rt->hilosClusterNodes[$nodeId];
        if ($node === null) {
            throw new TableActionException("Unknown cluster node: {$nodeId}");
        }
        if (!$node->online) {
            throw new TableActionException("Cluster node {$nodeId} is offline");
        }
    }
}
