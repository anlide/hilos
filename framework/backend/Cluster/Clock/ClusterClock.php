<?php

declare(strict_types=1);

namespace Hilos\Cluster\Clock;

use Closure;
use Hilos\Cluster\Peer\DTO\PeerClockProbeDTO;
use Hilos\Cluster\Peer\DTO\PeerClockReplyDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;

/**
 * Master-local clock exchanges: every node answers, only the leader measures.
 * Pending series belong to their actual link and need no timer beyond its liveness timeout.
 */
final class ClusterClock
{
    public const int SERIES_EXCHANGES = 5;

    public const string CRON_RULE = 'hilos_cluster_clock_check';

    public const string CRON_EXPRESSION = '0 4 * * *';

    private const int NS_PER_MICROSECOND = 1000;

    private const string SERIES_LINK = 'link';
    private const string SERIES_PROBE_ID = 'probeId';
    private const string SERIES_SENT_WALL_US = 'sentWallUs';
    private const string SERIES_SENT_MONO_NS = 'sentMonoNs';
    private const string SERIES_EXCHANGES_DONE = 'exchangesDone';
    private const string SERIES_BEST_ROUND_TRIP_US = 'bestRoundTripUs';
    private const string SERIES_BEST_OFFSET_US = 'bestOffsetUs';

    private bool $leader = false;
    private int $shiftMs = 0;
    private int $nextProbeId = 0;
    private readonly int $thresholdMs;

    /**
     * @var array<string, array{
     *     link: PeerLink, probeId: string, sentWallUs: int, sentMonoNs: int, exchangesDone: int,
     *     bestRoundTripUs: ?float, bestOffsetUs: ?float
     * }> In-flight series by peer id, private to the master holding these links
     */
    private array $series = [];

    /** @var array<string, ClusterClockReading> Last completed readings in this leader term */
    private array $nodeReadings = [];

    /** @var array<string, bool> Last threshold verdict in this leader term */
    private array $beyondThreshold = [];

    /**
     * @param Closure(): array<string, PeerLink> $links Current handshaked working links by node id
     * @param ?Closure(): int $wallTimeUs Epoch-microsecond source, or null for the process clock
     * @param ?Closure(): int $monotonicTimeNs Monotonic-nanosecond source, or null for hrtime
     * @param ?Closure(): int $nowMs Completion-moment source, or null for TimeHelper
     * @throws EnvException When the threshold cannot be read or is below one millisecond
     */
    public function __construct(
        private readonly Closure $links,
        private readonly ?Closure $wallTimeUs = null,
        private readonly ?Closure $monotonicTimeNs = null,
        private readonly ?Closure $nowMs = null,
    ) {
        $this->thresholdMs = Hilos::$env[EnvConstants::CLUSTER_CLOCK_SKEW_THRESHOLD_MS]->int();
        if ($this->thresholdMs < 1) {
            throw new EnvException('CLUSTER_CLOCK_SKEW_THRESHOLD_MS must be at least 1.');
        }
    }

    /**
     * @param PeerClockProbeDTO $probe Incoming correlated question
     * @return PeerClockReplyDTO This node's wall clock, including its test shift
     */
    public function answer(PeerClockProbeDTO $probe): PeerClockReplyDTO
    {
        return new PeerClockReplyDTO($probe->probeId, $this->wallTimeUs() + $this->shiftMs * TimeConstants::US_PER_MILLISECOND);
    }

    /**
     * @param int $shiftMs Test-only offset applied to answers, in milliseconds
     * @return int Applied offset in milliseconds
     */
    public function setTestShift(int $shiftMs): int
    {
        return $this->shiftMs = $shiftMs;
    }

    /** @return int Current test-only answer offset in milliseconds */
    public function testShiftMs(): int
    {
        return $this->shiftMs;
    }

    /** Begin fresh series for all linked peers in the new leader term. */
    public function onBecameLeader(): void
    {
        $this->leader = true;
        $this->checkAll();
    }

    /** Forget this term's exchanges, readings and threshold transitions. */
    public function onLostLeadership(): void
    {
        $this->leader = false;
        $this->series = [];
        $this->nodeReadings = [];
        $this->beyondThreshold = [];
    }

    /** @param string $nodeId Joined or returned peer; ignored on a follower */
    public function onNodeJoined(string $nodeId): void
    {
        if (!$this->leader) {
            return;
        }
        $link = ($this->links)()[$nodeId] ?? null;
        if ($link !== null) {
            $this->startSeries($nodeId, $link);
        }
    }

    /** Start over for every current working link; ignored on a follower. */
    public function checkAll(): void
    {
        if (!$this->leader) {
            return;
        }
        foreach (($this->links)() as $nodeId => $link) {
            $this->startSeries($nodeId, $link);
        }
    }

    /**
     * Advance only the exchange correlated to this exact link and probe id.
     *
     * @param PeerLink $link Answering link
     * @param PeerClockReplyDTO $reply Peer wall clock at its answer
     */
    public function onReply(PeerLink $link, PeerClockReplyDTO $reply): void
    {
        $receivedAtNs = $this->monotonicTimeNs();
        $nodeId = $link->remoteIdentity()?->nodeId;
        if (!$this->leader || $nodeId === null) {
            return;
        }
        $series = $this->series[$nodeId] ?? null;
        if ($series === null || $series[self::SERIES_LINK] !== $link || $series[self::SERIES_PROBE_ID] !== $reply->probeId) {
            return;
        }

        $roundTripUs = ($receivedAtNs - $series[self::SERIES_SENT_MONO_NS]) / self::NS_PER_MICROSECOND;
        $offsetUs = $reply->nodeTimeUs - ($series[self::SERIES_SENT_WALL_US] + $roundTripUs / 2);
        if ($series[self::SERIES_BEST_ROUND_TRIP_US] === null || $roundTripUs < $series[self::SERIES_BEST_ROUND_TRIP_US]) {
            $series[self::SERIES_BEST_ROUND_TRIP_US] = $roundTripUs;
            $series[self::SERIES_BEST_OFFSET_US] = $offsetUs;
        }
        ++$series[self::SERIES_EXCHANGES_DONE];
        if ($series[self::SERIES_EXCHANGES_DONE] < self::SERIES_EXCHANGES) {
            $this->series[$nodeId] = $series;
            $this->sendProbe($nodeId);
            return;
        }

        unset($this->series[$nodeId]);
        $reading = new ClusterClockReading(
            $nodeId,
            (int)round($series[self::SERIES_BEST_OFFSET_US] / TimeConstants::US_PER_MILLISECOND),
            (int)ceil($series[self::SERIES_BEST_ROUND_TRIP_US] / TimeConstants::US_PER_MILLISECOND),
            $this->nowMs === null ? TimeHelper::nowMs() : ($this->nowMs)(),
        );
        $this->nodeReadings[$nodeId] = $reading;
        $this->judge($reading);
    }

    /** @param PeerLink $link Closed link whose unfinished series must be abandoned */
    public function onLinkClosed(PeerLink $link): void
    {
        foreach ($this->series as $nodeId => $series) {
            if ($series[self::SERIES_LINK] === $link) {
                unset($this->series[$nodeId]);
            }
        }
    }

    /** @return list<ClusterClockReading> Completed readings in this leader term */
    public function readings(): array
    {
        return array_values($this->nodeReadings);
    }

    /** @return int Leader's configured skew threshold in milliseconds */
    public function thresholdMs(): int
    {
        return $this->thresholdMs;
    }

    /**
     * A fresh trigger replaces the whole series, including its previous best exchange.
     *
     * @param string $nodeId Measured peer
     * @param PeerLink $link Current working link
     */
    private function startSeries(string $nodeId, PeerLink $link): void
    {
        $this->series[$nodeId] = [
            self::SERIES_LINK => $link,
            self::SERIES_PROBE_ID => (string)$this->nextProbeId,
            self::SERIES_SENT_WALL_US => 0,
            self::SERIES_SENT_MONO_NS => 0,
            self::SERIES_EXCHANGES_DONE => 0,
            self::SERIES_BEST_ROUND_TRIP_US => null,
            self::SERIES_BEST_OFFSET_US => null,
        ];
        $this->sendProbe($nodeId);
    }

    /** @param string $nodeId Peer with a series ready for its next exchange */
    private function sendProbe(string $nodeId): void
    {
        $this->series[$nodeId][self::SERIES_PROBE_ID] = (string)++$this->nextProbeId;
        $this->series[$nodeId][self::SERIES_SENT_WALL_US] = $this->wallTimeUs();
        $this->series[$nodeId][self::SERIES_SENT_MONO_NS] = $this->monotonicTimeNs();
        $this->series[$nodeId][self::SERIES_LINK]->sendFrame(new PeerClockProbeDTO($this->series[$nodeId][self::SERIES_PROBE_ID]));
    }

    /** @return int Current epoch microseconds, without the test answer shift */
    private function wallTimeUs(): int
    {
        return $this->wallTimeUs === null ? (int)round(microtime(true) * TimeConstants::US_PER_SECOND) : ($this->wallTimeUs)();
    }

    /** @return int Current monotonic nanoseconds */
    private function monotonicTimeNs(): int
    {
        return $this->monotonicTimeNs === null ? hrtime(true) : ($this->monotonicTimeNs)();
    }

    /** @param ClusterClockReading $reading Newly completed series */
    private function judge(ClusterClockReading $reading): void
    {
        $beyond = abs($reading->offsetMs) > $this->thresholdMs;
        $wasBeyond = $this->beyondThreshold[$reading->nodeId] ?? false;
        $this->beyondThreshold[$reading->nodeId] = $beyond;
        $signedOffset = sprintf('%+d', $reading->offsetMs);
        if ($beyond && !$wasBeyond) {
            Logger::warning(
                "Clock of node {$reading->nodeId} is {$signedOffset} ms off the leader, "
                . "past the {$this->thresholdMs} ms threshold (best round trip {$reading->roundTripMs} ms)",
            );
        } elseif (!$beyond && $wasBeyond) {
            Logger::warning(
                "Clock of node {$reading->nodeId} is back within the {$this->thresholdMs} ms threshold of the leader: {$signedOffset} ms",
            );
        }
    }
}
