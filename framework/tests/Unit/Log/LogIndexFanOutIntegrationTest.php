<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Tests\Integration\BackupRestoreProgressSessionDeliveryIntegrationTest;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The aggregator and the agent of the log pages, talking over the wire they really use (HIL-756).
 *
 * A probe agent stands in for the browser, and the frames are taken off the signal router's queue
 * and put through `toArray()`/`fromArray()` before the other side sees them, rather than being
 * handed to either door directly. Calling the doors straight would test the register and the
 * mirror, which have unit tests of their own; what this leaf adds is the round trip between them,
 * and only a payload that has really travelled proves it.
 *
 * What each case says is who received, what they received, and how many frames it took - the three
 * questions {@see BackupRestoreProgressSessionDeliveryIntegrationTest} asks of a delivery, and the
 * three this one is worth asking of a subscription that nobody acknowledges.
 */
final class LogIndexFanOutIntegrationTest extends TestCase
{
    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** Past the coalescing window, so what is waiting becomes a frame. */
    private const float PAST_THE_WINDOW_SECONDS = 0.6;

    /** Past the subscriber's keepalive, so a claim goes out with nothing about it changed. */
    private const float PAST_THE_KEEPALIVE_SECONDS = 31.0;

    private string $logFile = '';

    /** @var float Instant this case started, the origin every offset is measured from */
    private float $startedAt = 0.0;

    protected function setUp(): void
    {
        // Outside any fixture on purpose: both agents log into the very directories they measure.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logindex-fanout-int-journal');
        Logger::setLogFile($this->logFile);
        Hilos::$sr = new SignalRouter();
        // No runtime context, so no connection roster to reconcile the viewers against: these cases
        // are about the frames, and a project without connections keeps the set its pages keep.
        Hilos::$rt = null;
        $this->emptyTheMirror();
        $this->startedAt = microtime(true);
    }

    protected function tearDown(): void
    {
        $this->emptyTheMirror();
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    /**
     * The whole circulation in one case: somebody opens a log page, the claim travels up, and the
     * picture the aggregator holds comes back down into the mirror the pages read.
     */
    public function testAViewerArrivingBringsTheWholePictureDown(): void
    {
        $aggregator = $this->startedAggregator();
        $aggregator->applyNodeIndex($this->nodeIndex('node-1', 100));
        $aggregator->applyNodeIndex($this->nodeIndex('node-2', 300));
        $pages = new LogIndexFanOutProbeAgent();

        ClusterLogIndexMirror::addViewer('ak-1');
        $pages->tickAt($this->at(0.0));

        $this->assertSame(1, $this->claimsTo($aggregator), 'One viewer owes exactly one claim');
        $this->assertSame(1, $this->portionsTo($pages), 'A claim that opens a subscription is answered once');
        $index = ClusterLogIndexMirror::index();
        $this->assertNotNull($index, 'The mirror knows a picture now');
        $this->assertSame(2, $index->totals()->nodeCount);
        $this->assertSame([LogKeySummary::CLASS_AGENT => 400], $index->totals()->bytesByClass);
    }

    /**
     * The window's job, seen from the receiving end: two nodes reporting one after the other arrive
     * as one frame carrying both, not as two frames carrying one each.
     */
    public function testTwoNodesReportingInsideTheWindowArriveAsOneFrame(): void
    {
        $aggregator = $this->subscribedAggregator($pages);

        $aggregator->applyNodeIndex($this->nodeIndex('node-1', 100));
        $aggregator->applyNodeIndex($this->nodeIndex('node-2', 300));
        $aggregator->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $frames = $this->framesTo($pages);
        $this->assertCount(1, $frames, 'Two reports inside one window owe one frame');
        $this->assertCount(2, $frames[0]->toSlots(), 'And that frame carries both of them');
        $this->assertSame(2, ClusterLogIndexMirror::index()?->totals()->nodeCount);
    }

    /**
     * The last tab closes and the frames stop at once, rather than at the end of a lease: that is
     * what the claim of zero is for, and what keeps an unwatched cluster silent.
     */
    public function testTheLastViewerLeavingStopsTheFramesImmediately(): void
    {
        $aggregator = $this->subscribedAggregator($pages);

        ClusterLogIndexMirror::removeViewer('ak-1');
        $pages->tickAt($this->at(0.1));
        $this->assertSame(1, $this->claimsTo($aggregator), 'Leaving owes a claim of its own');

        $aggregator->applyNodeIndex($this->nodeIndex('node-1', 100));
        $aggregator->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $this->assertSame([], $this->queuedFrames(), 'And nothing follows it');
    }

    /**
     * An aggregator moved by the placement policy or restarted comes up with an empty register, and
     * nobody tells it what it used to know. The subscription comes back on its own, with the next
     * claim the subscriber was going to send anyway.
     */
    public function testARestartedAggregatorIsResubscribedByTheNextKeepalive(): void
    {
        $this->subscribedAggregator($pages);

        $restarted = $this->startedAggregator();
        $restarted->applyNodeIndex($this->nodeIndex('node-1', 100));
        $restarted->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));
        $this->assertSame([], $this->queuedFrames(), 'It has never heard of this subscriber');

        $pages->tickAt($this->at(self::PAST_THE_KEEPALIVE_SECONDS));

        $this->assertSame(1, $this->claimsTo($restarted), 'The keepalive claim needs no prompting');
        $this->assertSame(1, $this->portionsTo($pages), 'And is answered with the whole picture');
        $this->assertSame(1, ClusterLogIndexMirror::index()?->totals()->nodeCount);
    }

    /**
     * An aggregator that is unplaced, moving, or simply not there leaves the section watching
     * nothing, and the wait is written down once - measured from the moment people started
     * waiting, so a section whose viewer count keeps changing is reported like any other.
     */
    public function testAWaitForAPictureThatNeverComesIsWrittenDownOnce(): void
    {
        $pages = new LogIndexFanOutProbeAgent();

        ClusterLogIndexMirror::addViewer('ak-1');
        $this->assertSame(0, $this->complaintsOfTick($pages, $this->at(0.0)), 'Nobody complains at once');
        // A second tab, which sends a claim of its own: a wait counted from the last claim would
        // start over here, and the one that matters - how long people have seen nothing - does not.
        ClusterLogIndexMirror::addViewer('ak-2');

        $complaints = $this->complaintsOfTick($pages, $this->at(self::PAST_THE_KEEPALIVE_SECONDS));

        $this->assertSame(1, $complaints, 'The wait is reported once it is long');
        $this->assertSame(
            0,
            $this->complaintsOfTick($pages, $this->at(self::PAST_THE_KEEPALIVE_SECONDS * 2)),
            'And not again on every tick after it',
        );
    }

    /**
     * A picture that arrives inside the span is no complaint at all: the aggregator was answering,
     * it merely had not answered YET.
     */
    public function testAPictureThatArrivesInTimeIsNoComplaint(): void
    {
        $this->subscribedAggregator($pages);

        $complaints = $this->complaintsOfTick($pages, $this->at(self::PAST_THE_KEEPALIVE_SECONDS));

        $this->assertSame(0, $complaints);
    }

    /**
     * @return LogAggregatorAgent Aggregator past its start hook, holding an empty picture
     */
    private function startedAggregator(): LogAggregatorAgent
    {
        $agent = new LogAggregatorAgent();
        $agent->onStart();

        return $agent;
    }

    /**
     * An aggregator that has answered one viewer's claim, with the queue drained after it.
     *
     * @param ?LogIndexFanOutProbeAgent $pages Filled in with the probe standing in for the browser
     * @return LogAggregatorAgent Aggregator holding that subscription
     */
    private function subscribedAggregator(?LogIndexFanOutProbeAgent &$pages): LogAggregatorAgent
    {
        $aggregator = $this->startedAggregator();
        $pages = new LogIndexFanOutProbeAgent();

        ClusterLogIndexMirror::addViewer('ak-1');
        $pages->tickAt($this->at(0.0));
        $this->assertSame(1, $this->claimsTo($aggregator));
        $this->assertSame(1, $this->portionsTo($pages));

        return $aggregator;
    }

    /**
     * Carries every queued claim to the aggregator through its wire form.
     *
     * The queue is emptied BEFORE anything is delivered, because the aggregator answers into the
     * same queue: carried one at a time, the answer to the first claim would be taken for a second
     * claim and handed to the wrong side.
     *
     * @param LogAggregatorAgent $aggregator Receiver to hand the claims to
     * @return int How many claims travelled
     */
    private function claimsTo(LogAggregatorAgent $aggregator): int
    {
        $carried = 0;
        foreach ($this->drain() as $signal) {
            $this->assertSame(HilosSignalConstants::LOGS_INDEX_WATCH, $signal->signalName->getName());
            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $this->assertInstanceOf(LogsIndexWatchSignalData::class, $signal->data->data);

            $aggregator->onSignalAgent(
                new AgentSignalData(data: LogsIndexWatchSignalData::fromArray($signal->data->data->toArray())),
                'agent',
                HilosSignalConstants::LOGS_INDEX_WATCH,
            );
            $carried++;
        }

        return $carried;
    }

    /**
     * Carries every queued portion to the agent of the log pages through its wire form.
     *
     * @param LogIndexFanOutProbeAgent $pages Receiver standing in for the browser
     * @return int How many portions travelled
     */
    private function portionsTo(LogIndexFanOutProbeAgent $pages): int
    {
        return count($this->framesTo($pages));
    }

    /**
     * Carries every queued portion down and hands back what travelled, for a case to read.
     *
     * @param LogIndexFanOutProbeAgent $pages Receiver standing in for the browser
     * @return list<ClusterLogIndexPortionSignalData> Frames as they arrived, in order
     */
    private function framesTo(LogIndexFanOutProbeAgent $pages): array
    {
        $frames = [];
        foreach ($this->drain() as $signal) {
            $this->assertSame(HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION, $signal->signalName->getName());
            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $this->assertInstanceOf(ClusterLogIndexPortionSignalData::class, $signal->data->data);

            $frame = ClusterLogIndexPortionSignalData::fromArray($signal->data->data->toArray());
            $pages->onSignalAgent(
                new AgentSignalData(data: $frame),
                'agent',
                HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION,
            );
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Takes everything the router holds right now, leaving the queue empty.
     *
     * @return list<SignalDTO> Queued signals, in the order they were sent
     */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * Drains the queue, so a case can say that nothing at all was sent.
     *
     * @return list<string> Name of every queued signal, in the order they were sent
     */
    private function queuedFrames(): array
    {
        return array_map(
            static fn (SignalDTO $signal): string => $signal->signalName->getName(),
            $this->drain(),
        );
    }

    /**
     * Runs one tick and counts what it said about people waiting on a picture.
     *
     * The journal is the only thing this behavior is observable through - nothing is sent and
     * nothing is stored - and an agent writes it to the output rather than to the log file, so the
     * tick is run with the output captured.
     *
     * @param LogIndexFanOutProbeAgent $pages Probe standing in for the browser
     * @param float $now Wall clock of this tick
     * @return int Lines this tick wrote about viewers waiting
     */
    private function complaintsOfTick(LogIndexFanOutProbeAgent $pages, float $now): int
    {
        ob_start();
        try {
            $pages->tickAt($now);
        } finally {
            $written = (string)ob_get_clean();
        }

        return substr_count($written, 'viewer(s) waiting');
    }

    /**
     * A clock reading the given number of seconds after this case started.
     *
     * @param float $seconds Seconds after the start
     * @return float Wall clock that many seconds past it
     */
    private function at(float $seconds): float
    {
        return $this->startedAt + $seconds;
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
     * @param string $nodeId Node the frame came from
     * @param int $totalBytes Weight of that node's one agent key
     * @return NodeLogIndex Index as that node would report it
     */
    private function nodeIndex(string $nodeId, int $totalBytes): NodeLogIndex
    {
        return new NodeLogIndex(
            nodeId: $nodeId,
            available: true,
            sampledAt: self::T0,
            batches: [],
            keys: [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], $totalBytes)],
            workers: [],
            growthBytesPerDay: [],
        );
    }
}

/**
 * The concrete logs agent a project would write, standing in for the browser side of the wire.
 *
 * It adds one door and nothing else: a tick with the clock handed in, because the keepalive it is
 * driven by is thirty seconds and a test must not wait for them.
 */
final class LogIndexFanOutProbeAgent extends AbstractHilosLogsAgent
{
    /**
     * Runs the claim half of the tick at a clock of the case's choosing.
     *
     * The page half is left out deliberately: it broadcasts to the connections of the overview
     * page, which is a different leaf's wire and has nothing to do with this one.
     *
     * @param float $now Wall clock of this tick
     */
    public function tickAt(float $now): void
    {
        $this->watchIfDue($now);
    }
}
