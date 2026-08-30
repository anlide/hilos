<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\NodeLogIndex;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * When the aggregator hands its picture up, and when it says nothing at all (HIL-756).
 *
 * The giving half of the fan-out, driven through {@see LogAggregatorAgent::fanOutIfDue()} with the
 * clock handed in, the way {@see LogStoreAgent::pushIndexIfDue()} is driven one level down: the tick
 * is only that method's throttle, and a test of a ninety-second lease must not take ninety seconds.
 *
 * What is held down here is the shape of the rule rather than its numbers. A cluster nobody is
 * looking at costs nothing; a page that has just been opened does not wait for a window to see what
 * is already in memory; a busy log does not become a busy network; and a subscriber is told only
 * what it has not been told before.
 */
final class LogIndexFanOutTest extends TestCase
{
    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** The one subscriber a live cluster has: the agent serving the log pages. */
    private const string SUBSCRIBER = 'agent';

    /** A second signal source, for the cases about the register keeping its subscribers apart. */
    private const string OTHER_SUBSCRIBER = 'agent-other';

    /** Past the coalescing window, so a change that is waiting becomes a frame. */
    private const float PAST_THE_WINDOW_SECONDS = 0.6;

    /** Inside the coalescing window, where a change is not yet worth a frame. */
    private const float INSIDE_THE_WINDOW_SECONDS = 0.2;

    /** Past the lease, so a subscriber that never renewed is forgotten. */
    private const float PAST_THE_LEASE_SECONDS = 91.0;

    private string $logFile = '';

    /** @var float Instant the agent under test was started, the origin every offset is measured from */
    private float $startedAt = 0.0;

    protected function setUp(): void
    {
        // The aggregator writes into the very directory it measures, so the journal goes elsewhere.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logindex-fanout-journal');
        Logger::setLogFile($this->logFile);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    /**
     * The whole point of the lease: an installation whose administrators are all asleep pays for
     * the frames coming UP from its nodes and for nothing else.
     */
    public function testNothingGoesOutWhileNobodyClaimsToBeWatching(): void
    {
        $agent = $this->startedAgent();

        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $agent->applyNodeIndex($this->nodeIndex('node-2'));
        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $agent->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $this->assertSame([], $this->queuedFrames());
    }

    /**
     * A page that has just been opened has nothing to coalesce, so making it wait for the window
     * would be the window charging for work it did not do.
     */
    public function testTheFirstClaimIsAnsweredWithTheWholePictureAtOnce(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $agent->applyNodeIndex($this->nodeIndex('node-2'));

        $this->watch($agent, 1);

        $frame = $this->frame();
        $this->assertTrue($frame->snapshot);
        $this->assertSame(['node-1', 'node-2'], $this->nodeIds($frame));
    }

    /**
     * A node with nothing in its picture yet still gets an answer: the mirror has to be able to
     * tell an aggregator that has nothing from one that is not there at all.
     */
    public function testTheFirstClaimIsAnsweredEvenWhenNoNodeHasReported(): void
    {
        $agent = $this->startedAgent();

        $this->watch($agent, 1);

        $frame = $this->frame();
        $this->assertTrue($frame->snapshot);
        $this->assertSame([], $frame->toSlots());
    }

    /**
     * The window's whole job: a hundred frames arriving from below must not become a hundred frames
     * going up, and the subscriber loses nothing by it - each slot travels whole, so the last state
     * of every node is in the one frame that goes.
     */
    public function testAHundredChangesInsideTheWindowBecomeOneFrame(): void
    {
        $agent = $this->startedAgent();
        $this->watch($agent, 1);
        $this->frame();

        for ($i = 0; $i < 100; $i++) {
            $agent->applyNodeIndex($this->nodeIndex('node-1', keys: [$this->key('agent-a.log', $i)]));
        }
        $agent->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $frame = $this->frame();
        $this->assertFalse($frame->snapshot);
        $this->assertSame(['node-1'], $this->nodeIds($frame));
        $this->assertSame(99, $frame->toSlots()[0]->index->keys[0]->totalBytes, 'The frame carries the latest state');
        $this->assertSame([], $this->queuedFrames(), 'A hundred changes owe exactly one frame');
    }

    public function testAChangeInsideTheWindowIsNotYetWorthAFrame(): void
    {
        $agent = $this->startedAgent();
        $this->watch($agent, 1);
        $this->frame();

        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $agent->fanOutIfDue($this->at(self::INSIDE_THE_WINDOW_SECONDS));

        $this->assertSame([], $this->queuedFrames());
    }

    /**
     * A portion is what changed and not what there is: sending the whole picture every time would
     * make the frame grow with the size of the cluster rather than with what happened in it.
     */
    public function testAPortionCarriesOnlyTheSlotsThatChangedSinceTheLastOne(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $agent->applyNodeIndex($this->nodeIndex('node-2'));
        $this->watch($agent, 1);
        $this->frame();

        $agent->applyNodeIndex($this->nodeIndex('node-2', keys: [$this->key('agent-a.log', 100)]));
        $agent->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $this->assertSame(['node-2'], $this->nodeIds($this->frame()));
    }

    /**
     * Nothing changed means nothing sent, however long the window has been open: a quiet cluster
     * costs no frames at all rather than one per tick carrying what the subscriber already has.
     */
    public function testATickWithNothingNewSendsNothing(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $this->watch($agent, 1);
        $this->frame();

        $agent->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $this->assertSame([], $this->queuedFrames());
    }

    /**
     * Zero is a cancellation and it takes effect at once rather than at the end of the lease: the
     * tab has closed, and a stream of frames outliving the viewer by ninety seconds is exactly what
     * the count was made to prevent.
     */
    public function testAClaimOfZeroCancelsTheSubscriptionImmediately(): void
    {
        $agent = $this->startedAgent();
        $this->watch($agent, 1);
        $this->frame();

        $this->watch($agent, 0);
        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $agent->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $this->assertSame([], $this->queuedFrames());
    }

    /**
     * A subscriber renews on its own tick, so missing three in a row means the process holding it
     * is gone - and the frames it would have received have nowhere to arrive anyway.
     */
    public function testASubscriberSilentPastTheLeaseIsForgotten(): void
    {
        $agent = $this->startedAgent();
        $this->watch($agent, 1);
        $this->frame();

        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $agent->fanOutIfDue($this->at(self::PAST_THE_LEASE_SECONDS));

        $this->assertSame([], $this->queuedFrames());
    }

    /**
     * A renewal keeps the subscription alive without answering it again: a claim arriving every
     * thirty seconds must not put a snapshot on the wire every thirty seconds.
     */
    public function testARenewedClaimIsNotAnsweredWithASecondSnapshot(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $this->watch($agent, 1);
        $this->frame();

        $this->watch($agent, 2);

        $this->assertSame([], $this->queuedFrames());
    }

    /**
     * How far a subscriber has been told is remembered per subscriber and not once for everybody:
     * a shared mark would let one subscriber's frame count as another's and leave the second
     * quietly a slot behind.
     */
    public function testASecondSubscriberIsToldWhatItHasNotBeenToldItself(): void
    {
        $agent = $this->startedAgent();
        $agent->applyNodeIndex($this->nodeIndex('node-1'));
        $this->watch($agent, 1);
        $this->assertSame(['node-1'], $this->nodeIds($this->frame()));

        $agent->applyNodeIndex($this->nodeIndex('node-2'));
        $this->watch($agent, 1, self::OTHER_SUBSCRIBER);
        $this->assertSame(['node-1', 'node-2'], $this->nodeIds($this->frame()), 'The newcomer gets everything');

        $agent->fanOutIfDue($this->at(self::PAST_THE_WINDOW_SECONDS));

        $frame = $this->frame();
        $this->assertSame(['node-2'], $this->nodeIds($frame), 'The first one is owed only what it missed');
        $this->assertSame([], $this->queuedFrames(), 'The newcomer is owed nothing');
    }

    /**
     * @return LogAggregatorAgent Started agent, holding an empty picture and no subscriber
     */
    private function startedAgent(): LogAggregatorAgent
    {
        $agent = new LogAggregatorAgent();
        $agent->onStart();
        $this->startedAt = microtime(true);

        return $agent;
    }

    /**
     * Hands the agent one claim of interest, the way the router would.
     *
     * @param LogAggregatorAgent $agent Agent under test
     * @param int $viewers Viewers the subscriber claims, zero to cancel
     * @param string $source Signal source of that subscriber
     */
    private function watch(LogAggregatorAgent $agent, int $viewers, string $source = self::SUBSCRIBER): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(new LogsIndexWatchSignalData($viewers)),
            $source,
            HilosSignalConstants::LOGS_INDEX_WATCH,
        );
    }

    /**
     * A clock reading the given number of seconds after the agent was started.
     *
     * Measured from the instant of the start and NOT from a fresh reading: the agent stamps its
     * last frame with the real clock, so a reading taken at the assertion would carry whatever the
     * case did in between into the elapsed time.
     *
     * @param float $seconds Seconds after the start
     * @return float Wall clock that many seconds past it
     */
    private function at(float $seconds): float
    {
        return $this->startedAt + $seconds;
    }

    /**
     * Takes the one frame the queue is expected to hold.
     *
     * @return ClusterLogIndexPortionSignalData Payload of that frame
     */
    private function frame(): ClusterLogIndexPortionSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'A frame was due and nothing was sent');
        $this->assertSame(HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(ClusterLogIndexPortionSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Drains the queue, so a case can say that nothing at all was sent.
     *
     * @return list<string> Name of every queued signal, in the order they were sent
     */
    private function queuedFrames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }

    /**
     * @param ClusterLogIndexPortionSignalData $frame Frame to read
     * @return list<?string> Node id of every slot the frame carries, in the order it carries them
     */
    private function nodeIds(ClusterLogIndexPortionSignalData $frame): array
    {
        return array_map(
            static fn ($slot): ?string => $slot->nodeId,
            $frame->toSlots(),
        );
    }

    /**
     * @param ?string $nodeId Node the frame came from, null for a single-node installation
     * @param list<LogKeySummary> $keys Log keys the node reported
     * @return NodeLogIndex Frame as a node would send it
     */
    private function nodeIndex(?string $nodeId, array $keys = []): NodeLogIndex
    {
        return new NodeLogIndex($nodeId, true, self::T0, [], $keys, [], []);
    }

    /**
     * @param string $key File basename
     * @param int $totalBytes Weight of the key, live file and every batch of it together
     * @return LogKeySummary Key summary as a node's walk would project it
     */
    private function key(string $key, int $totalBytes): LogKeySummary
    {
        return new LogKeySummary($key, LogKeySummary::CLASS_AGENT, true, [], $totalBytes);
    }
}
