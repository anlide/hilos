<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
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
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;
use Hilos\Pages\Logs\DTO\HilosLogsRotationsSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the header the rotation-history page answers a subscription with (HIL-387).
 *
 * The batches ride the ordinary windowed table and are not tested here; what is, is everything the
 * screen needs BEFORE a row can mean anything. Which of the four empty states it is in, because
 * "nobody has reported" and "no node can read its store" are different screens and neither of them
 * is a zero. Which nodes exist, because an installation with no node id at all must lose its node
 * column rather than offer a filter with one entry in it. And that a viewer who leaves is dropped
 * from the page's own set AND from the section's count - a viewer left counted keeps the
 * aggregator sending frames for a page nobody has open.
 */
final class HilosLogsRotationsPageSubscribeTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-rotations-1';

    /** A second connection, for the case where one admin's arrival must not silence another's push. */
    private const string SECOND_ACCEPT_KEY = 'ak-rotations-2';

    /** Comfortably past the ~100ms throttle {@see AbstractHilosLogsRotationsPage::onAgentTick()} keeps. */
    private const int PAST_THE_TICK_THROTTLE_MICROSECONDS = 150_000;

    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** Weight of each fixture node's one batch; each move of it moves the rows fingerprint. */
    private int $batchBytes = 0;

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
        AbstractHilosLogsRotationsPage::removeSubscriber(self::ACCEPT_KEY);
        AbstractHilosLogsRotationsPage::removeSubscriber(self::SECOND_ACCEPT_KEY);
        $this->emptyTheMirror();

        foreach ([
            EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES,
            EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS,
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS,
            EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES,
            EnvConstants::LOG_ROTATION_CRON,
        ] as $key) {
            putenv($key->name);
        }

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
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
                SignalTypeConstants::PAGE_RESPONSE,
            ],
            $this->queuedSignalNames(),
        );
    }

    /**
     * The rules on the screen are the ones that REALLY act: the resolver has already fallen back
     * to the environment wherever a setting could not be used, so the header shows what rotation
     * will do rather than what the settings table says it should.
     */
    public function testTheHeaderCarriesTheRulesInForce(): void
    {
        putenv(EnvConstants::LOG_ROTATION_CRON->name . '=0 4 * * *');
        putenv(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name . '=3600');
        putenv(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name . '=1048576');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=7');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=604800');
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $header = $this->header();
        $this->assertSame('0 4 * * *', $header->rotationCron);
        $this->assertSame(3_600, $header->rotationMaxAgeSeconds);
        $this->assertSame(1_048_576, $header->rotationMaxLiveSizeBytes);
        $this->assertSame(7, $header->retentionKeepBatches);
        $this->assertSame(604_800, $header->retentionMaxAgeSeconds);
    }

    /**
     * Opening the screen before the aggregator has answered gives the third state and not zeros:
     * we have not heard, which is a different screen from "we heard and nothing can be read".
     */
    public function testAnEmptyMirrorAnswersWithTheThirdState(): void
    {
        $this->emptyTheMirror();
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

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
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertFalse($this->header()->available);
    }

    /**
     * One node that can be read is enough to have a history to draw, even while a neighbour
     * cannot: the rows of the readable node exist and the screen must not hide them.
     */
    public function testOneReadableNodeIsEnoughToHaveAHistory(): void
    {
        $this->picture($this->slot('node-1', available: false), $this->slot('node-2', available: true));
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

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
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $header = $this->header();
        $this->assertSame([], $header->nodes);
        $this->assertTrue($header->available);
    }

    public function testAClusterNamesEveryNodeThatHasReported(): void
    {
        $this->picture($this->slot('node-2', available: true), $this->slot('node-1', available: true));
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());

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
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->assertSame([self::ACCEPT_KEY], ClusterLogIndexMirror::viewerKeys());
        $this->assertSame([self::ACCEPT_KEY], $this->tickRecipients());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame(0, ClusterLogIndexMirror::viewerCount());
        $this->assertSame([], $this->tickRecipients());
    }

    /**
     * A second admin opening the page must not cancel the push the first one is still owed.
     *
     * The two fingerprints are one pair for the whole process and say what the last BROADCAST
     * carried. A subscribe that moved them to "current" would mark a change nobody had been sent
     * yet as already delivered, and the admins already on the page would go on looking at the
     * previous picture until the next change happened to come along.
     */
    public function testASecondSubscriberDoesNotSwallowThePushTheFirstIsOwed(): void
    {
        $page = new LogsRotationsPageSubscribeTestPage(new LogsRotationsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        // One tick with nothing changed, so the fingerprints hold THIS picture whatever an
        // earlier case in this process left them holding: they are static, and the point of the
        // case is what the NEXT change does.
        $this->tickPastTheThrottle();
        $this->queuedSignalNames();

        // The picture moves, and the throttled tick has not run yet when the second admin arrives.
        $this->growTheClusterPicture();
        $page->onSubscribe(self::SECOND_ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->tickPastTheThrottle();

        $keys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $keys[] = (string) $signal->data->targetAcceptKey;
        }
        sort($keys);
        $this->assertSame([self::ACCEPT_KEY, self::SECOND_ACCEPT_KEY], $keys);
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
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
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
        AbstractHilosLogsRotationsPage::onAgentTick(new LogsRotationsPageSubscribeTestAgent());
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
     * @return HilosLogsRotationsSignalData Payload of the first queued signal
     */
    private function header(): HilosLogsRotationsSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The subscription answers with a header');
        $this->assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
            $signal->signalName->getName(),
        );
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(HilosLogsRotationsSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Files one more frame of the cluster picture, with one node more and heavier batches than the
     * last, so both fingerprints the page compares against differ from what they were.
     *
     * The node count is what moves the HEADER: the batch weights live in the rows and would leave
     * a header push with no reason to happen, which is exactly the tick's own rule.
     */
    private function growTheClusterPicture(): void
    {
        $this->batchBytes += 100;
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
     * Builds one node's slot holding a single batch.
     *
     * @param ?string $nodeId Node the slot belongs to, null in a single-node installation
     * @param bool $available Whether that node could read its log store
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function slot(?string $nodeId, bool $available): ClusterLogNodeSlot
    {
        // An unreadable store reports empty projections, the way NodeLogIndex carries the state:
        // zeros there would claim a walk that never happened.
        $batches = $available
            ? [new LogBatchSummary(self::T0 - 3_600, 1, $this->batchBytes, 0, 0, 0, 0, 0, 0)]
            : [];

        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: $available,
                sampledAt: self::T0,
                batches: $batches,
                keys: [],
                workers: [],
                growthBytesPerDay: [],
            ),
            receivedAt: self::T0,
        );
    }
}

/**
 * Concrete stand-in for a project's rotation-history page, which adds nothing of its own.
 */
final class LogsRotationsPageSubscribeTestPage extends AbstractHilosLogsRotationsPage
{
}

/**
 * Minimal page agent providing a signal source for sendToUser and for the tick push.
 */
final class LogsRotationsPageSubscribeTestAgent implements PageAgentInterface
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
