<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsPage;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how the logs page ends a subscription (HIL-702).
 *
 * The page carries an overview signal of its own, and until this leaf it sent that signal
 * INSTEAD of the page_response frame every other page closes a subscription with - so the
 * section arrived without the heading, lead and breadcrumb its catalog entry holds. What the
 * tests hold down is the order of the two answers, the identity riding the second one, and that
 * a connection joins the tick's push list only once the subscription has actually been answered.
 *
 * Since HIL-756 the figures come from the CLUSTER picture in {@see ClusterLogIndexMirror} rather
 * than from a walk of this node's own directory, so the cases move the mirror instead of writing
 * files - and one of them asks what the page answers when no picture has arrived at all, which is
 * a third state the payload gained with the same leaf.
 */
final class HilosLogsPageSubscribeTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-logs-1';

    private const string REFUSED_ACCEPT_KEY = 'ak-logs-refused';

    /** Comfortably past the ~100ms throttle {@see AbstractHilosLogsPage::onAgentTick()} keeps. */
    private const int PAST_THE_TICK_THROTTLE_MICROSECONDS = 150_000;

    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** How many bytes the fixture node's one key holds; each move of it moves the fingerprint */
    private int $keyBytes = 0;

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
        AbstractHilosLogsPage::removeSubscriber(self::ACCEPT_KEY);
        AbstractHilosLogsPage::removeSubscriber(self::REFUSED_ACCEPT_KEY);
        $this->emptyTheMirror();

        Hilos::$sr = null;
        Hilos::$browser = null;

        parent::tearDown();
    }

    /**
     * Two answers, in this order and no other: the page's own overview first, because the frame
     * behind it means the subscription is answered in full.
     */
    public function testTheSubscriptionAnswersWithTheOverviewAndThenTheFrame(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS,
                SignalTypeConstants::PAGE_RESPONSE,
            ],
            $this->queuedSignalNames(),
        );
    }

    /**
     * The overview is the CLUSTER's, projected out of the mirror: the page walks no directory, so
     * these are figures every node reported and not the ones lying on whichever node answered.
     */
    public function testTheOverviewIsProjectedFromTheClusterPicture(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertTrue($overview->available);
        $this->assertSame(1, $overview->totalRotationsAllTime);
        $this->assertSame(1, $overview->logKeysPerAgent);
        $this->assertSame(100, $overview->totalWeightAgentKeysBytes);
        $this->assertSame(1, $overview->logKeysPerWorker);
        $this->assertSame(300, $overview->totalWeightWorkerKeysBytes);
    }

    /**
     * Opening the page before the aggregator has answered gives the third state and not zeros: the
     * figures are UNKNOWN, where false would report every log store in the cluster as unreadable
     * and a zero would claim a measurement nobody took.
     */
    public function testAnEmptyMirrorAnswersWithTheThirdState(): void
    {
        $this->emptyTheMirror();
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertNull($overview->available);
        $this->assertNull($overview->totalRotationsAllTime);
        $this->assertNull($overview->logKeysPerAgent);
        $this->assertNull($overview->totalWeightWorkerKeysBytes);
    }

    /**
     * Subscribing counts the connection as a viewer of the section, which is the only thing that
     * makes the aggregator send anything: without it the mirror would stay empty for good.
     */
    public function testSubscribingCountsTheConnectionAsAViewerOfTheSection(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame([self::ACCEPT_KEY], ClusterLogIndexMirror::viewerKeys());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame(0, ClusterLogIndexMirror::viewerCount());
    }

    /**
     * The frame the page gained carries the identity the catalog holds for it, which is the whole
     * point of answering with it: the page itself writes none of these three keys.
     */
    public function testTheFrameCarriesTheCatalogIdentityOfTheLogsPage(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        Hilos::$sr?->getNextQueuedSignal();
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => HilosPageConstants::HILOS_LOGS,
                PageResponseSignalData::payload => [
                    PagePayload::data => [
                        PageCatalogConstants::WIRE_PAGE_LABEL => 'Logs',
                        PageCatalogConstants::WIRE_PAGE_LEAD =>
                            'Rotation stats, log keys, workers, and the viewer.',
                        PageCatalogConstants::WIRE_PAGE_BREADCRUMB => [
                            [
                                PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_DASHBOARD,
                                PageCatalogConstants::WIRE_CRUMB_LABEL => 'Hilos',
                            ],
                            [
                                PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS,
                                PageCatalogConstants::WIRE_CRUMB_LABEL => 'Logs',
                            ],
                        ],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    /**
     * A subscription that never got its answer leaves no subscriber: the overview went out ahead
     * of the refusal, the frame did not, and the connection stays off the tick's push list -
     * which is why the registration sits in the after-response hook and not beside the snapshot.
     */
    public function testARefusedSubscriptionLeavesNoSubscriberBehind(): void
    {
        Hilos::$browser = new LogsPageSubscribeTestRefusingBrowser();
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        try {
            $page->onSubscribe(self::REFUSED_ACCEPT_KEY, new PageRouteParams([]));
            $this->fail('The refusing browser must not let the subscription through.');
        } catch (PageInternalErrorException $exception) {
            $this->assertSame('Refused before the answer', $exception->getMessage());
        }

        $this->assertSame([HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS], $this->queuedSignalNames());
        $this->assertSame([], $this->tickRecipients());
        $this->assertSame(0, ClusterLogIndexMirror::viewerCount(), 'And no viewer either');
    }

    /**
     * Leaving the page takes the connection off the push list the tick reads.
     */
    public function testUnsubscribeTakesTheConnectionOffThePushList(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->assertSame([self::ACCEPT_KEY], $this->tickRecipients());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame([], $this->tickRecipients());
    }

    /**
     * Runs one tick past its throttle, with the log store changed underneath so the overview
     * fingerprint really differs and a push has a reason to go out. An empty result therefore
     * means "nobody is registered" rather than "nothing happened".
     *
     * @return list<string> Accept keys the tick pushed the overview to
     */
    private function tickRecipients(): array
    {
        $this->growTheClusterPicture();
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsPage::onAgentTick(new LogsPageSubscribeTestAgent());

        $keys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS, $signal->signalName->getName());
            $keys[] = (string)$signal->data->targetAcceptKey;
        }

        return $keys;
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
     * Files one more frame of the cluster picture, heavier than the last, so the overview scalars -
     * and with them the fingerprint the page compares against - are not what they were a moment ago.
     */
    private function growTheClusterPicture(): void
    {
        $this->keyBytes += 100;
        ClusterLogIndexMirror::applyPortion(ClusterLogIndexPortionSignalData::ofSlots(
            [
                new ClusterLogNodeSlot(
                    nodeId: 'node-1',
                    index: new NodeLogIndex(
                        nodeId: 'node-1',
                        available: true,
                        sampledAt: self::T0,
                        batches: [new LogBatchSummary(self::T0 - 3600, 1, 10, 0, 0, 0, 0, 0, 0)],
                        keys: [
                            new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], $this->keyBytes),
                            new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [], 300),
                        ],
                        workers: [],
                        growthBytesPerDay: [],
                    ),
                    receivedAt: self::T0,
                ),
            ],
            true,
        ));
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
     * Takes the overview the page answered a subscription with.
     *
     * @return HilosLogsOverviewSignalData Payload of the first queued signal
     */
    private function overview(): HilosLogsOverviewSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The subscription answers with an overview');
        $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(HilosLogsOverviewSignalData::class, $signal->data->data);

        return $signal->data->data;
    }
}

/**
 * Concrete stand-in for a project's logs page, which adds nothing of its own.
 */
final class LogsPageSubscribeTestPage extends AbstractHilosLogsPage
{
}

/**
 * Browser refusing the snapshot the way a malformed page declaration does, so the subscription
 * fails between the overview and the frame.
 */
final class LogsPageSubscribeTestRefusingBrowser extends BrowserContext
{
    /**
     * @param string $page Page name from the subscription request
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Route params for this page subscription
     * @throws PageInternalErrorException Always; the fixture exists to refuse
     */
    public function subscribeSnapshot(string $page, string $acceptKey, PageRouteParams $params): void
    {
        throw new PageInternalErrorException('Refused before the answer');
    }
}

/**
 * Minimal page agent providing a signal source for sendToUser and for the tick broadcast.
 */
final class LogsPageSubscribeTestAgent implements PageAgentInterface
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
