<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;
use Hilos\Pages\Logs\DTO\HilosLogsViewCatalogSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the catalog of sources the log viewer answers a subscription with (HIL-388).
 *
 * The lines themselves ride the `logs_read_lines` action and are not tested here; what is, is
 * everything the screen needs BEFORE a file can be named. Which of its empty states it is in,
 * because "nobody has reported" and "no node can read its store" are different screens and neither
 * of them is an empty list. What a node is called when the installation has no cluster at all,
 * because that name is the segment the reading action is addressed by. And that a viewer who leaves
 * is dropped from the page's own set AND from the section's count - a viewer left counted keeps the
 * aggregator sending frames for a page nobody has open.
 */
final class HilosLogsViewPageCatalogTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-logs-view-1';

    /** Comfortably past the ~100ms throttle {@see AbstractHilosLogsViewPage::onAgentTick()} keeps. */
    private const int PAST_THE_TICK_THROTTLE_MICROSECONDS = 150_000;

    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** How many nodes the fixture picture holds; each one more moves the catalog fingerprint. */
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
        AbstractHilosLogsViewPage::removeSubscriber(self::ACCEPT_KEY);
        $this->emptyTheMirror();

        Hilos::$sr = null;
        Hilos::$browser = null;

        parent::tearDown();
    }

    /**
     * Two answers, in this order and no other: the catalog first, because the frame behind it
     * means the subscription is answered in full.
     */
    public function testTheSubscriptionAnswersWithTheCatalogAndThenTheFrame(): void
    {
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW,
                SignalTypeConstants::PAGE_RESPONSE,
            ],
            $this->queuedSignalNames(),
        );
    }

    /**
     * Opening the screen before the aggregator has answered gives the third state and not an empty
     * catalog: we have not heard, which is a different screen from "we heard and nothing can be
     * read".
     */
    public function testAnEmptyMirrorAnswersWithTheThirdState(): void
    {
        $this->emptyTheMirror();
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $catalog = $this->catalog();
        $this->assertNull($catalog->available);
        $this->assertSame([], $catalog->nodes);
    }

    /**
     * A picture in which no node can read its store is the other screen, and it is a fault to show
     * rather than a wait to sit through. The nodes stay listed all the same: choosing one is how
     * the operator is told WHY there is nothing, where a missing node reads as one that never was.
     */
    public function testAPictureWhereNoNodeCanReadItsStoreIsUnavailable(): void
    {
        $this->picture($this->slot('node-1', available: false), $this->slot('node-2', available: false));
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $catalog = $this->catalog();
        $this->assertFalse($catalog->available);
        $this->assertCount(2, $catalog->nodes);
        $this->assertFalse($catalog->nodes[0][HilosLogsViewCatalogSignalData::available]);
    }

    /**
     * One node that can be read is enough to have something to open, even while a neighbour cannot.
     */
    public function testOneReadableNodeIsEnoughToHaveSomethingToOpen(): void
    {
        $this->picture($this->slot('node-1', available: false), $this->slot('node-2', available: true));
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertTrue($this->catalog()->available);
    }

    /**
     * An installation with no cluster reports under no name, and the empty string is the name it
     * gets here: the segment travels in the address and in the read request, where the page already
     * reads it as "this node". Leaving the node out instead would give the screen nothing to
     * address its read to.
     */
    public function testASingleNodeInstallationIsNamedByTheEmptyString(): void
    {
        $this->picture($this->slot(null, available: true));
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $catalog = $this->catalog();
        $this->assertCount(1, $catalog->nodes);
        $this->assertSame(
            HilosLogsViewCatalogSignalData::SINGLE_NODE_ID,
            $catalog->nodes[0][HilosLogsViewCatalogSignalData::nodeId],
        );
    }

    /**
     * What a node offers: the batches it holds, and the streams with the batches each occurs in.
     * The screen needs both to keep a chosen stream across a change of source instead of dropping
     * it whenever the file exists under the new one.
     */
    public function testANodeCarriesItsBatchesAndItsStreams(): void
    {
        $this->picture($this->slot('node-1', available: true));
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $node = $this->catalog()->nodes[0];
        $this->assertSame('node-1', $node[HilosLogsViewCatalogSignalData::nodeId]);
        $this->assertSame([self::T0 - 3_600], $node[HilosLogsViewCatalogSignalData::batches]);
        $this->assertSame(
            [
                [
                    HilosLogsViewCatalogSignalData::key => 'worker-0.log',
                    HilosLogsViewCatalogSignalData::streamClass => LogKeySummary::CLASS_WORKER,
                    HilosLogsViewCatalogSignalData::live => true,
                    HilosLogsViewCatalogSignalData::batchTimestamps => [self::T0 - 3_600],
                ],
            ],
            $node[HilosLogsViewCatalogSignalData::streams],
        );
    }

    /**
     * The catalog survives the crossing it is built for: what goes out reads back as itself.
     */
    public function testTheCatalogReadsBackFromItsWireForm(): void
    {
        $this->picture($this->slot('node-1', available: true));

        $catalog = HilosLogsViewCatalogSignalData::fromIndex(ClusterLogIndexMirror::index());

        $this->assertSame(
            $catalog->toArray(),
            HilosLogsViewCatalogSignalData::fromArray($catalog->toArray())->toArray(),
        );
    }

    /**
     * Subscribing counts the connection as a viewer of the SECTION, which is the only thing that
     * makes the aggregator send anything; leaving takes it off both lists at once.
     */
    public function testLeavingDropsTheViewerFromThePageAndFromTheSection(): void
    {
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->assertSame([self::ACCEPT_KEY], ClusterLogIndexMirror::viewerKeys());
        $this->assertSame([self::ACCEPT_KEY], $this->tickRecipients());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame(0, ClusterLogIndexMirror::viewerCount());
        $this->assertSame([], $this->tickRecipients());
    }

    /**
     * The tick pushes on a CHANGE and not on a beat: the catalog is re-sent whole, so a screen
     * left open would otherwise be repainted ten times a second for a picture standing still.
     */
    public function testTheTickPushesOnlyWhenTheCatalogChanged(): void
    {
        $page = new LogsViewPageCatalogTestPage(new LogsViewPageCatalogTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        // One tick with nothing changed, so the fingerprint holds THIS picture whatever an earlier
        // case in this process left it holding: it is static, and the point of the case is what
        // the NEXT tick does.
        $this->tickPastTheThrottle();
        $this->queuedSignalNames();
        $this->tickPastTheThrottle();

        $this->assertSame([], $this->queuedSignalNames());

        $this->growTheClusterPicture();
        $this->tickPastTheThrottle();

        $this->assertSame(
            [HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW],
            $this->queuedSignalNames(),
        );
    }

    /**
     * Runs one tick past its throttle, with the picture changed underneath so the catalog really
     * differs and a push has a reason to go out. An empty result therefore means "nobody is
     * registered" rather than "nothing happened".
     *
     * @return list<string> Accept keys the tick pushed the catalog to
     */
    private function tickRecipients(): array
    {
        $this->growTheClusterPicture();
        $this->tickPastTheThrottle();

        $keys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame(
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW,
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
        AbstractHilosLogsViewPage::onAgentTick(new LogsViewPageCatalogTestAgent());
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
     * Takes the catalog the page answered a subscription with.
     *
     * @return HilosLogsViewCatalogSignalData Payload of the first queued signal
     */
    private function catalog(): HilosLogsViewCatalogSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The subscription answers with a catalog');
        $this->assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW,
            $signal->signalName->getName(),
        );
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(HilosLogsViewCatalogSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Files one more frame of the cluster picture, with one node more than the last, so the
     * fingerprint the page compares against differs from what it was.
     *
     * @return void
     */
    private function growTheClusterPicture(): void
    {
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
     * Builds one node's slot holding a single batch and a single stream.
     *
     * @param ?string $nodeId Node the slot belongs to, null in a single-node installation
     * @param bool $available Whether that node could read its log store
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function slot(?string $nodeId, bool $available): ClusterLogNodeSlot
    {
        // An unreadable store reports empty projections, the way NodeLogIndex carries the state:
        // zeros there would claim a walk that never happened.
        $batches = $available ? [new LogBatchSummary(self::T0 - 3_600, 0, 0, 1, 100, 0, 0, 0, 0)] : [];
        $keys = $available
            ? [new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [self::T0 - 3_600], 100)]
            : [];

        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: $available,
                sampledAt: self::T0,
                batches: $batches,
                keys: $keys,
                workers: [],
                growthBytesPerDay: [],
            ),
            receivedAt: self::T0,
        );
    }
}

/**
 * Concrete stand-in for a project's log viewer page, which adds nothing of its own.
 */
final class LogsViewPageCatalogTestPage extends AbstractHilosLogsViewPage
{
}

/**
 * Minimal page agent providing a signal source for sendToUser and for the tick push.
 */
final class LogsViewPageCatalogTestAgent implements PageAgentInterface
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
