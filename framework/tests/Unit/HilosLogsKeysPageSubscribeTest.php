<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;
use Hilos\Pages\Logs\DTO\HilosLogsKeysSignalData;
use Hilos\Tables\Logs\HilosLogKeysTable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the header the log-keys page answers a subscription with (HIL-385).
 *
 * The streams ride the ordinary windowed table and are not tested here; what is, is everything the
 * screen needs BEFORE a row can mean anything. Which of the four empty states it is in, because
 * "nobody has reported" and "no node can read its store" are different screens and neither of them
 * is a zero. Which nodes exist, because an installation with no node id at all must lose its node
 * column rather than offer a filter with one entry in it. That a viewer who leaves is dropped from
 * the page's own set AND from the section's count. And that a window is re-served when the streams
 * moved and not when the tick merely came round again.
 */
final class HilosLogsKeysPageSubscribeTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-keys-1';

    /** Comfortably past the ~100ms throttle {@see AbstractHilosLogsKeysPage::onAgentTick()} keeps. */
    private const int PAST_THE_TICK_THROTTLE_MICROSECONDS = 150_000;

    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** Weight of each fixture node's one stream; each move of it moves the rows fingerprint. */
    private int $streamBytes = 0;

    /** How many nodes the fixture picture holds; each one more moves the header fingerprint. */
    private int $reportedNodes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        // Binds the base facade, whose page catalog answers for the framework admin pages. The
        // base creates no browser context, so this clears the browser in the same call.
        Hilos::initBrowser();

        $this->emptyTheMirror();
        $this->growTheClusterPicture();
    }

    protected function tearDown(): void
    {
        // The subscriber set is static and outlives the test; a key left in it would push at
        // nobody for the rest of the suite. The mirror is static for the same reason.
        AbstractHilosLogsKeysPage::removeSubscriber(self::ACCEPT_KEY);
        $this->emptyTheMirror();

        Hilos::$sr = null;
        Hilos::$browser = null;

        parent::tearDown();
    }

    /**
     * Two answers, in this order and no other: the page's own header first, because the frame
     * behind it means the subscription is answered in full.
     */
    public function testTheSubscriptionAnswersWithTheHeaderAndThenTheFrame(): void
    {
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS,
                SignalTypeConstants::PAGE_RESPONSE,
            ],
            $this->queuedSignalNames(),
        );
    }

    /**
     * Opening the screen before the aggregator has answered gives the third state and not zeros:
     * we have not heard, which is a different screen from "we heard and nothing can be read".
     */
    public function testAnEmptyMirrorAnswersWithTheThirdState(): void
    {
        $this->emptyTheMirror();
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertNull($this->header()->available);
    }

    /**
     * A picture in which no node can read its store is the other screen, and it is a fault to show
     * rather than a wait to sit through.
     */
    public function testAPictureWhereNoNodeCanReadItsStoreIsUnavailable(): void
    {
        $this->picture($this->slot('node-1', available: false), $this->slot('node-2', available: false));
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertFalse($this->header()->available);
    }

    /**
     * One node that can be read is enough to have streams to draw, even while a neighbour cannot:
     * the rows of the readable node exist and the screen must not hide them.
     */
    public function testOneReadableNodeIsEnoughToHaveStreams(): void
    {
        $this->picture($this->slot('node-1', available: false), $this->slot('node-2', available: true));
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertTrue($this->header()->available);
    }

    /**
     * An installation with no node id at all reports under no name, and the empty list is how the
     * screen is told to drop its node column and node filter rather than offer a choice of one.
     */
    public function testASingleNodeInstallationNamesNoNodes(): void
    {
        $this->picture($this->slot(null, available: true));
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $header = $this->header();
        $this->assertSame([], $header->nodes);
        $this->assertTrue($header->available);
    }

    public function testAClusterNamesEveryNodeThatHasReported(): void
    {
        $this->picture($this->slot('node-2', available: true), $this->slot('node-1', available: true));
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        // The picture holds its slots ordered by node, so the filter offers them the same way
        // however the frames happened to arrive.
        $this->assertSame(['node-1', 'node-2'], $this->header()->nodes);
    }

    /**
     * Subscribing counts the connection as a viewer of the SECTION, which is the only thing that
     * makes the aggregator send anything; leaving takes it off both lists at once.
     */
    public function testLeavingDropsTheViewerFromThePageAndFromTheSection(): void
    {
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->assertSame([self::ACCEPT_KEY], ClusterLogIndexMirror::viewerKeys());
        $this->assertSame([self::ACCEPT_KEY], $this->tickRecipients());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame(0, ClusterLogIndexMirror::viewerCount());
        $this->assertSame([], $this->tickRecipients());
    }

    /**
     * The window is re-served when what the streams are built from moved, and not because a tick
     * came round: the picture is re-sent whole by every node on its own schedule, and a window per
     * frame would rebuild a table nothing had happened to.
     */
    public function testTheTickReservesTheWindowOnlyWhenTheStreamsMoved(): void
    {
        $browser = $this->mountBrowser();
        $page = new LogsKeysPageSubscribeTestPage(new LogsKeysPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();
        Hilos::$sr?->setTableViewport(
            self::ACCEPT_KEY,
            new TableViewportSubscription(tableKey: HilosLogKeysTable::TABLE),
        );

        // One tick to settle the fingerprints on the current picture, whatever an earlier case in
        // this process left them holding: they are static, and the point is what the NEXT one does.
        $this->tickPastTheThrottle();
        $browser->windows = [];

        $this->tickPastTheThrottle();
        $this->assertSame([], $browser->windows);

        $this->growTheClusterPicture();
        $this->tickPastTheThrottle();
        $this->assertSame([HilosLogKeysTable::TABLE], $browser->windows);
    }

    /**
     * Runs one tick past its throttle, with the picture changed underneath so the header really
     * differs and a push has a reason to go out. An empty result therefore means "nobody is
     * registered" rather than "nothing happened".
     *
     * @return list<string> Accept keys the tick pushed the header to
     */
    private function tickRecipients(): array
    {
        $this->growTheClusterPicture();
        $this->tickPastTheThrottle();

        $keys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame(
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS,
                $signal->signalName->getName(),
            );
            $keys[] = (string) $signal->data->targetAcceptKey;
        }

        return $keys;
    }

    /**
     * Waits out the tick's throttle and runs one tick.
     */
    private function tickPastTheThrottle(): void
    {
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsKeysPage::onAgentTick(new LogsKeysPageSubscribeTestAgent());
    }

    /**
     * Drains the queue into the signal names it holds.
     *
     * @return list<string> Queued signal names, oldest first
     */
    private function queuedSignalNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }

    /**
     * Takes the header the page answered a subscription with.
     *
     * @return HilosLogsKeysSignalData Payload of the first queued signal
     */
    private function header(): HilosLogsKeysSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The subscription answers with a header');
        $this->assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS,
            $signal->signalName->getName(),
        );
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(HilosLogsKeysSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Mounts the browser fixture that records a window delivery instead of building one.
     *
     * @return LogsKeysPageSubscribeTestBrowser Mounted browser context fixture
     */
    private function mountBrowser(): LogsKeysPageSubscribeTestBrowser
    {
        $browser = new LogsKeysPageSubscribeTestBrowser();
        Hilos::$browser = $browser;

        return $browser;
    }

    /**
     * Files one more frame of the cluster picture, with one node more and heavier streams than the
     * last, so both fingerprints the page compares against differ from what they were.
     */
    private function growTheClusterPicture(): void
    {
        $this->streamBytes += 100;
        $this->reportedNodes++;

        $slots = [];
        for ($node = 1; $node <= $this->reportedNodes; $node++) {
            $slots[] = $this->slot('node-' . $node, available: true);
        }
        $this->picture(...$slots);
    }

    /**
     * Files a whole cluster picture into the mirror the page reads.
     *
     * @param ClusterLogNodeSlot ...$slots Node slots the picture is made of
     */
    private function picture(ClusterLogNodeSlot ...$slots): void
    {
        ClusterLogIndexMirror::applyPortion(
            ClusterLogIndexPortionSignalData::ofSlots(array_values($slots), true),
        );
    }

    /**
     * The mirror belongs to the worker process, so a case leaves it as it found it.
     */
    private function emptyTheMirror(): void
    {
        ClusterLogIndexMirror::forgetPicture();
        foreach (ClusterLogIndexMirror::viewerKeys() as $acceptKey) {
            ClusterLogIndexMirror::removeViewer($acceptKey);
        }
    }

    /**
     * Builds one node's slot holding a single stream.
     *
     * @param ?string $nodeId Node the slot belongs to, null in a single-node installation
     * @param bool $available Whether that node could read its log store
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function slot(?string $nodeId, bool $available): ClusterLogNodeSlot
    {
        // An unreadable store reports empty projections, the way NodeLogIndex carries the state:
        // zeros there would claim a walk that never happened.
        $keys = $available
            ? [new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [], $this->streamBytes)]
            : [];

        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: $available,
                sampledAt: self::T0,
                batches: [],
                keys: $keys,
                workers: [],
                growthBytesPerDay: [],
            ),
            receivedAt: self::T0,
        );
    }
}

/**
 * Concrete stand-in for a project's log-keys page, which adds nothing of its own.
 */
final class LogsKeysPageSubscribeTestPage extends AbstractHilosLogsKeysPage
{
}

/**
 * Browser context fixture recording the window deliveries the tick asked for.
 */
final class LogsKeysPageSubscribeTestBrowser extends BrowserContext
{
    /** @var list<string> Table keys whose window delivery was reached */
    public array $windows = [];

    /**
     * Records the window delivery instead of building one from a table nothing mounted.
     *
     * @param string $page Page the table belongs to (unused)
     * @param string $acceptKey Subscribing WebSocket accept key (unused)
     * @param TableViewportSubscription $viewport Window descriptor
     * @return bool Always true; reaching this method is what the test asserts
     */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): bool
    {
        $this->windows[] = $viewport->tableKey;

        return true;
    }
}

/**
 * Minimal page agent providing a signal source for sendToUser and for the tick push.
 */
final class LogsKeysPageSubscribeTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_logs';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'hilos_logs');
    }
}
