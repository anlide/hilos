<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Clock;

use Hilos\Cluster\Clock\ClusterClock;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerClockProbeDTO;
use Hilos\Cluster\Peer\DTO\PeerClockReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestMarkers;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestTls;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;

/** Clock sources are advanced by the test; no exchange waits for real time. */
final class ClusterClockTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousThreshold;
    private string $logFile;

    /** @var list<Socket> Unconnected handles; frames remain in the link's write buffer */
    private array $sockets = [];

    /** @var array<string, PeerLink> Current working links offered to the measurement */
    private array $links = [];

    private int $wallUs = 1_800_000_000_000_000;
    private int $monoNs = 1_000_000_000;
    private int $completedAt = 1_800_000_000_123;
    private ClusterClock $clock;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousThreshold = getenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS');
        putenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS=300');
        Hilos::$env = new EnvAccessor();
        $this->logFile = tempnam(sys_get_temp_dir(), 'hilos-clock-test-');
        Logger::setLogFile($this->logFile);
        $this->clock = $this->newClock();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Logger::resetLogFile();
        unlink($this->logFile);
        putenv($this->previousThreshold === false
            ? 'CLUSTER_CLOCK_SKEW_THRESHOLD_MS'
            : 'CLUSTER_CLOCK_SKEW_THRESHOLD_MS=' . $this->previousThreshold);
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    public function testFiveSequentialExchangesChooseTheShortestMonotonicRoundTrip(): void
    {
        $link = $this->link('node-b');
        $this->clock->onBecameLeader();
        self::assertCount(1, $this->probes($link));
        self::assertSame([], $this->clock->readings());

        // Wall time jumps between exchanges; elapsed time must still come from the monotonic clock.
        foreach ([[8000, 50_000], [6000, -20_000], [1501, -3750], [4000, 80_000], [3000, 90_000]] as $index => [$rttUs, $offsetUs]) {
            $this->reply($link, $rttUs, $offsetUs);
            self::assertCount(min($index + 2, 5), $this->probes($link));
            if ($index < 4) {
                self::assertSame([], $this->clock->readings());
            }
        }

        [$reading] = $this->clock->readings();
        self::assertSame('node-b', $reading->nodeId);
        self::assertSame(-4, $reading->offsetMs);
        self::assertSame(2, $reading->roundTripMs);
        self::assertSame($this->completedAt, $reading->measuredAt);
        self::assertCount(5, array_unique(array_map(static fn (PeerClockProbeDTO $probe): string => $probe->probeId, $this->probes($link))));

        $this->clock->onReply($link, new PeerClockReplyDTO($this->lastProbe($link)->probeId, $this->wallUs));
        self::assertCount(5, $this->probes($link), 'A duplicate final reply starts no exchange');
    }

    public function testANewTriggerRestartsTheEntireSeriesAndDropsItsOldBest(): void
    {
        $link = $this->link('node-b');
        $this->clock->onBecameLeader();
        $this->reply($link, 100, 0);
        $oldProbe = $this->lastProbe($link);

        $this->clock->checkAll();
        $newProbe = $this->lastProbe($link);
        self::assertNotSame($oldProbe->probeId, $newProbe->probeId);
        $this->clock->onReply($link, new PeerClockReplyDTO($oldProbe->probeId, $this->wallUs));
        self::assertCount(3, $this->probes($link), 'The abandoned reply does not advance the new series');

        $this->finishSeries($link, 812);
        self::assertSame(812, $this->clock->readings()[0]->offsetMs);
        self::assertSame(2, $this->clock->readings()[0]->roundTripMs);
    }

    public function testAnUnrelatedProbeOrAnotherLinkCannotAdvanceTheSeries(): void
    {
        $link = $this->link('node-b');
        $other = $this->link('node-b');
        $this->links['node-b'] = $link;
        $this->clock->onBecameLeader();
        $probe = $this->lastProbe($link);

        $this->clock->onReply($link, new PeerClockReplyDTO('other-probe', $this->wallUs));
        $this->clock->onReply($other, new PeerClockReplyDTO($probe->probeId, $this->wallUs));
        self::assertCount(1, $this->probes($link));
        self::assertSame([], $this->probes($other));
        self::assertSame([], $this->clock->readings());

        $this->clock->onLinkClosed($other);
        $this->finishSeries($link, 12);
        self::assertSame(12, $this->clock->readings()[0]->offsetMs);
    }

    public function testClosingALinkAbandonsItsSeriesAndRetainsTheLastCompletedReading(): void
    {
        $link = $this->link('node-b');
        $this->clock->onBecameLeader();
        $this->finishSeries($link, 25);
        $previous = $this->clock->readings();
        $this->clock->checkAll();
        $this->reply($link, 100, 500_000);
        $probe = $this->lastProbe($link);

        $this->clock->onLinkClosed($link);
        $this->clock->onReply($link, new PeerClockReplyDTO($probe->probeId, $this->wallUs));
        self::assertCount(7, $this->probes($link));
        self::assertSame($previous, $this->clock->readings());
    }

    public function testClosingTheFirstSeriesPublishesNothing(): void
    {
        $link = $this->link('node-b');
        $this->clock->onBecameLeader();
        $this->clock->onLinkClosed($link);
        $this->clock->onReply($link, new PeerClockReplyDTO($this->lastProbe($link)->probeId, $this->wallUs));
        self::assertSame([], $this->clock->readings());
        self::assertCount(1, $this->probes($link));
    }

    public function testOnlyThresholdTransitionsLogAndTheBoundaryIsWithin(): void
    {
        $link = $this->link('node-b');
        $this->clock->onBecameLeader();
        $this->finishSeries($link, 300);
        self::assertSame([], $this->warnings(), 'The first reading exactly at the threshold is within');

        foreach ([812, 900, 300, -300, -301, -400, -3] as $offsetMs) {
            $this->clock->checkAll();
            $this->finishSeries($link, $offsetMs);
        }

        self::assertSame([
            'Clock of node node-b is +812 ms off the leader, past the 300 ms threshold (best round trip 2 ms)',
            'Clock of node node-b is back within the 300 ms threshold of the leader: +300 ms',
            'Clock of node node-b is -301 ms off the leader, past the 300 ms threshold (best round trip 2 ms)',
            'Clock of node node-b is back within the 300 ms threshold of the leader: -3 ms',
        ], $this->warnings());
    }

    public function testTheFirstOutOfBoundsReadingLogsAndRecoveryAtZeroHasAnExplicitSign(): void
    {
        $link = $this->link('node-b');
        $this->clock->onBecameLeader();
        $this->finishSeries($link, -812);
        $this->clock->checkAll();
        $this->finishSeries($link, 0);
        self::assertSame([
            'Clock of node node-b is -812 ms off the leader, past the 300 ms threshold (best round trip 2 ms)',
            'Clock of node node-b is back within the 300 ms threshold of the leader: +0 ms',
        ], $this->warnings());
    }

    public function testLeadershipLossClearsReadingsSeriesAndVerdicts(): void
    {
        $link = $this->link('node-b');
        $this->clock->onBecameLeader();
        $this->finishSeries($link, 812);
        $this->clock->checkAll();
        $oldProbe = $this->lastProbe($link);

        $this->clock->onLostLeadership();
        self::assertSame([], $this->clock->readings());
        $this->clock->onNodeJoined('node-b');
        $this->clock->checkAll();
        $this->clock->onReply($link, new PeerClockReplyDTO($oldProbe->probeId, $this->wallUs));
        self::assertCount(6, $this->probes($link));

        $this->clock->onBecameLeader();
        self::assertNotSame($oldProbe->probeId, $this->lastProbe($link)->probeId);
        $this->finishSeries($link, 812);
        self::assertCount(2, $this->warnings(), 'A new leader term has no memory of the old verdict');
    }

    public function testAFollowerStartsNoSeriesAndAJoinOnTheLeaderMeasuresOnlyThatPeer(): void
    {
        $first = $this->link('node-b');
        $second = $this->link('node-c');
        $this->clock->onNodeJoined('node-b');
        $this->clock->checkAll();
        self::assertSame([], $this->probes($first));
        self::assertSame([], $this->probes($second));

        $this->clock->onBecameLeader();
        self::assertCount(1, $this->probes($first));
        self::assertCount(1, $this->probes($second));
        $this->clock->onNodeJoined('node-c');
        $this->clock->onNodeJoined('not-linked');
        self::assertCount(1, $this->probes($first));
        self::assertCount(2, $this->probes($second));
    }

    public function testAnswersEchoTheProbeAndApplyOnlyTheAnswerShift(): void
    {
        self::assertSame(0, $this->clock->testShiftMs());
        self::assertSame(812, $this->clock->setTestShift(812));
        self::assertSame(812, $this->clock->testShiftMs());
        $reply = $this->clock->answer(new PeerClockProbeDTO('probe-9'));
        self::assertSame('probe-9', $reply->probeId);
        self::assertSame($this->wallUs + 812_000, $reply->nodeTimeUs);
        $this->clock->setTestShift(-3);
        self::assertSame($this->wallUs - 3000, $this->clock->answer(new PeerClockProbeDTO('probe-10'))->nodeTimeUs);
        $this->clock->setTestShift(0);
        self::assertSame($this->wallUs, $this->clock->answer(new PeerClockProbeDTO('probe-11'))->nodeTimeUs);
    }

    public function testDefaultAndConfiguredThresholds(): void
    {
        putenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS');
        self::assertSame(300, $this->newClock()->thresholdMs());
        putenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS=1');
        self::assertSame(1, $this->newClock()->thresholdMs());
        self::assertNull(new ClusterContext()->clock());
    }

    /** @return iterable<string, array{int}> Invalid startup thresholds */
    public static function invalidThresholds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /** @param int $threshold Invalid startup value */
    #[DataProvider('invalidThresholds')]
    public function testThresholdMustBePositive(int $threshold): void
    {
        putenv('CLUSTER_CLOCK_SKEW_THRESHOLD_MS=' . $threshold);
        $this->expectException(EnvException::class);
        $this->newClock();
    }

    /** @return ClusterClock Measurement with manually advanced clocks */
    private function newClock(): ClusterClock
    {
        return new ClusterClock(
            fn (): array => $this->links,
            fn (): int => $this->wallUs,
            fn (): int => $this->monoNs,
            fn (): int => $this->completedAt,
        );
    }

    /**
     * @param string $nodeId Peer behind the link
     * @return PeerLink Link that queues frames without network I/O
     */
    private function link(string $nodeId): PeerLink
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        self::assertInstanceOf(Socket::class, $socket);
        $this->sockets[] = $socket;
        $identity = NodeIdentity::of('leader', NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $identity, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $link = new PeerLink($socket, $server, $identity, false, new PlainSocketTransport($socket));
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($link, NodeIdentity::of($nodeId, NodeRole::Slave, []));
        $this->links[$nodeId] = $link;
        return $link;
    }

    /**
     * @param PeerLink $link Measured peer link
     * @return list<PeerClockProbeDTO> All probes queued on that link
     */
    private function probes(PeerLink $link): array
    {
        $buffer = new ReflectionProperty(AbstractClient::class, 'writeBuffer')->getValue($link);
        if ($buffer === '') {
            return [];
        }
        return array_map(static function (string $line): PeerClockProbeDTO {
            $frame = PeerDTO::fromWire($line);
            self::assertInstanceOf(PeerClockProbeDTO::class, $frame);
            return $frame;
        }, explode("\n", rtrim($buffer, "\n")));
    }

    /**
     * @param PeerLink $link Measured link
     * @return PeerClockProbeDTO Most recently queued probe
     */
    private function lastProbe(PeerLink $link): PeerClockProbeDTO
    {
        $probes = $this->probes($link);
        self::assertNotEmpty($probes);
        return $probes[array_key_last($probes)];
    }

    /**
     * @param PeerLink $link Answering link
     * @param int $roundTripUs Simulated elapsed monotonic microseconds
     * @param int $offsetUs Simulated peer-minus-leader offset in microseconds
     */
    private function reply(PeerLink $link, int $roundTripUs, int $offsetUs): void
    {
        $probe = $this->lastProbe($link);
        $nodeTimeUs = $this->wallUs + (int)round($roundTripUs / 2) + $offsetUs;
        $this->monoNs += $roundTripUs * 1000;
        $this->wallUs -= 7_000_000;
        $this->clock->onReply($link, new PeerClockReplyDTO($probe->probeId, $nodeTimeUs));
    }

    /**
     * @param PeerLink $link Peer with a freshly started series
     * @param int $offsetMs Offset to report throughout the series
     */
    private function finishSeries(PeerLink $link, int $offsetMs): void
    {
        for ($exchange = 0; $exchange < 5; ++$exchange) {
            $this->reply($link, 2000, $offsetMs * 1000);
        }
    }

    /** @return list<string> Exact warning messages, with the logger prefix removed */
    private function warnings(): array
    {
        $messages = [];
        foreach (file($this->logFile, FILE_IGNORE_NEW_LINES) as $line) {
            self::assertStringContainsString('WARNING', $line);
            $start = strpos($line, 'Clock of node');
            self::assertNotFalse($start);
            $messages[] = substr($line, $start);
        }
        return $messages;
    }
}
