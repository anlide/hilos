<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndex;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\LogWorkerSummary;
use Hilos\Pages\Logs\DTO\HilosLogsWorkersSignalData;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use JsonException;

/**
 * Abstract Hilos admin page: the worker log streams of the installation, one row per stream on a node.
 *
 * The screen the by-key one hands the question on to: it holds the distinction that one folds away,
 * whether a worker is the monopolistic one or an ordinary one. An administrator arrives here asking
 * "the log has grown — was it the ordinary workers or the single one that holds the shared work",
 * and those two have different causes and different cures.
 *
 * Two things travel to this screen and they travel apart. The header — whether there is a picture
 * and which nodes it holds — is this page's own signal, sent once on subscribe and again whenever
 * it changes. The streams ride the ordinary windowed table ({@see HilosLogWorkersTable}) over
 * `table_viewport` → `table_window`, so scrolling and sorting cost a window rather than a header.
 *
 * Like the by-key screen ({@see AbstractHilosLogsKeysPage}), every connection that subscribes is
 * also counted as a viewer of the SECTION: without that the aggregator sends nothing, the mirror
 * stays empty, and this screen would be permanently empty rather than merely new.
 *
 * The window is re-sent and not patched. A `table_viewport_delta` is built from a
 * {@see SourceChange}, which is a DB or runtime event; the mirror is neither and raises none, so
 * there is nothing a delta could be made of. Re-sending costs nothing under the hand either — each
 * connection's own descriptor (its sort, filter and page) is what the window is rebuilt from, so an
 * administrator stays where they stood.
 *
 * Projects register a concrete empty subclass in the page factory (wiring only).
 */
abstract class AbstractHilosLogsWorkersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_WORKERS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS,
    ];

    /** Seconds between two tick refreshes, so a busy agent does not rebuild the header per loop pass. */
    private const float REFRESH_THROTTLE_SECONDS = 0.1;

    /** @var array<string, true> WebSocket accept keys currently subscribed to this page */
    private static array $subscribers = [];

    /** @var ?float Wall clock ({@see microtime()}) of the last tick refresh, null before the first */
    private static ?float $lastRefreshAt = null;

    /**
     * @var string Fingerprint of what the streams were built from when the windows were last sent
     *
     * Of the sources and not of a window: a window is per connection (its own sort, filter and
     * page), while the question "is there anything new to show" is one question for everybody.
     */
    private static string $lastRowsFingerprint = '';

    /** @var string Fingerprint of the header payload last sent, so an unchanged header is not re-sent */
    private static string $lastHeaderFingerprint = '';

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
     * changed header goes out in the same pass.
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
     * Drops subscriber state when the client leaves this page.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        $this->logAgentInfo("hilos_logs_workers onUnsubscribe acceptKey={$acceptKey}");
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
        $this->logAgentInfo("hilos_logs_workers onSubscribe acceptKey={$acceptKey}");

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS,
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
     * Builds the header out of the cluster picture.
     *
     * @return HilosLogsWorkersSignalData Header payload
     */
    private static function buildHeader(): HilosLogsWorkersSignalData
    {
        $index = ClusterLogIndexMirror::index();

        return new HilosLogsWorkersSignalData(
            available: self::availabilityOf($index),
            nodes: self::namedNodesOf($index),
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
     * @return ?bool True when there are streams to list, false when no node can read its store,
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
     * Re-serves every subscriber's own window of the log-workers table.
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
            $viewport = Hilos::$sr?->getTableViewport($acceptKey, HilosLogWorkersTable::TABLE);
            if ($viewport === null) {
                continue;
            }

            Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_LOGS_WORKERS, $acceptKey, $viewport);
        }
    }

    /**
     * Fingerprint of everything the worker-stream rows are projected from.
     *
     * The stream figures rather than the frame's arrival time: a node re-sends its index whole on
     * its own schedule, so an arrival time would move on every frame and re-send every window for
     * a picture that had not changed at all. The daily growth is not among them, unlike on the
     * by-key screen — this one draws no growth column, and a figure the table cannot show is not a
     * reason to re-send its windows.
     *
     * @param HilosLogsWorkersSignalData $header Header of this pass, for the availability it carries
     * @return string Stable fingerprint of what the rows are built from
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    private static function rowsFingerprint(HilosLogsWorkersSignalData $header): string
    {
        $slots = [];
        foreach (ClusterLogIndexMirror::index()?->nodes() ?? [] as $slot) {
            $slots[] = [
                $slot->nodeId,
                $slot->index->available,
                array_map(
                    static fn(LogWorkerSummary $summary): array => [
                        $summary->key,
                        $summary->monopolistic,
                        $summary->live,
                        $summary->batchTimestamps,
                        $summary->totalBytes,
                    ],
                    $slot->index->workers,
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
     * @param HilosLogsWorkersSignalData $header Payload
     * @throws InvalidArgumentException When the header signal cannot be named
     */
    private static function queueHeaderToUser(
        PageAgentInterface $agent,
        string $acceptKey,
        HilosLogsWorkersSignalData $header,
    ): void {
        Hilos::$sr?->queueSignal(
            signalSource: $agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS),
            signalData: new WebSocketSignalData(data: $header, targetAcceptKey: $acceptKey),
        );
    }
}
