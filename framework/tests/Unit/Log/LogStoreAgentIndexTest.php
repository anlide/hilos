<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use DateTimeImmutable;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\LogGrowthWindow;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogStoreAgent;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the node log index the store agent holds (HIL-753).
 *
 * The agent is driven through its two walks rather than through its tick, because the tick is only
 * a throttle and its intervals are wall-clock seconds: {@see LogStoreAgent::walkStore()} takes the
 * timestamp to stamp the walk with, so a day of a growth window fits in a test that runs in
 * milliseconds. The store underneath is a throwaway temp directory that the tests grow, rotate and
 * empty by hand, the fixture shape borrowed from {@see LogRotatorTest}.
 *
 * The leaf exists so the index is not born dead the way HIL-379 was, and what these tests hold
 * down is exactly the behavior that has no consumer yet: the delta between two walks, the day
 * window, and what happens to both when the directory cannot be read.
 */
final class LogStoreAgentIndexTest extends TestCase
{
    /** A day plus one sampling step, so a window stamped at the first walk is old enough to answer. */
    private const int PAST_THE_DAY_WINDOW_SECONDS = 86400 + 900;

    /**
     * A day less than one sampling step: old enough for the FIRST point and too young for the
     * second, which is how a window reports the growth of the whole day rather than of its tail.
     */
    private const int JUST_INSIDE_THE_DAY_WINDOW_SECONDS = 86400 + 400;

    /** Any fixed instant, for the tests that drive a growth window and no agent. */
    private const int T0 = 1_800_000_000;

    /** Keep-count under which no batch is protected by being among the newest. */
    private const string KEEP_NO_BATCHES = '0';

    /** Age past which a batch is recommended for takeout; every fixture batch here is from 2026-08. */
    private const string EVICT_AFTER_A_SECOND = '1';

    /** Keep-count that protects the fixture archive by being wider than it. */
    private const string KEEP_MORE_THAN_THERE_ARE = '10';

    /**
     * @var int Baseline the agent tests place their walks relative to. It is the real clock,
     *     because onStart() stamps its own baseline walk with time() and a synthetic origin
     *     elsewhere on the timeline would make that first point either a day old or the future.
     */
    private int $t0 = 0;

    private string $dir = '';

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->t0 = time();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logstore-agent-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the very directory it measures, and a
        // journal written there would show up in the index the assertions read.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logstore-agent-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        putenv(EnvConstants::DAEMON_ERROR_LOG_FILE->name . '=' . $this->dir . '/daemon-error.log');
        // The agent reports its index to the cluster aggregator from its start hook onward
        // (HIL-755), so a router has to be there for the frames to queue into. What the frames
        // carry is {@see LogIndexPushTest}'s subject; here they are only the side effect of a walk.
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        putenv(EnvConstants::DAEMON_ERROR_LOG_FILE->name);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name);
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->removeTree($this->dir);
    }

    public function testAppendedBytesArriveAsGrowthInTheDelta(): void
    {
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();

        $this->write('agent-hilos_logs.log', 250);
        $agent->walkStore($this->t0 + 60);

        $this->assertSame(['agent-hilos_logs.log' => 150], $agent->lastDelta()?->grownKeys);
        $this->assertSame(250, $this->keyBytes($agent, 'agent-hilos_logs.log'));
    }

    public function testAppearedAndVanishedKeysAreNamedInTheDelta(): void
    {
        $this->write('agent-one.log', 10);
        $agent = $this->startedAgent();

        $this->write('agent-two.log', 20);
        unlink($this->dir . DIRECTORY_SEPARATOR . 'agent-one.log');
        $agent->walkStore($this->t0 + 60);

        $this->assertSame(['agent-two.log'], $agent->lastDelta()?->appearedKeys);
        $this->assertSame(['agent-one.log'], $agent->lastDelta()?->vanishedKeys);
    }

    public function testRotationTurnsTheKeyArchiveOnlyAndAddsABatchWithoutLosingGrowth(): void
    {
        $this->write('agent-hilos_logs.log', 300);
        $agent = $this->startedAgent();

        $timestamp = $this->rotate('2026-08-01-00-00-00');
        $agent->walkStore($this->t0 + self::PAST_THE_DAY_WINDOW_SECONDS);

        $key = $this->key($agent, 'agent-hilos_logs.log');
        $this->assertNotNull($key);
        // Same key, same weight - it is only in the archive now.
        $this->assertFalse($key->live);
        $this->assertSame([$timestamp], $key->batchTimestamps);
        $this->assertSame(300, $key->totalBytes);
        $this->assertSame([$timestamp], $agent->lastDelta()?->appearedBatchTimestamps);
        // Rotation is a move, not a write: it adds nothing, and above all subtracts nothing.
        $this->assertSame(0, $agent->index()->growthBytesPerDay['agent-hilos_logs.log']);
    }

    public function testDeletedBatchDropsTheWeightWithoutNegativeGrowth(): void
    {
        $this->write('agent-hilos_logs.log', 300);
        $agent = $this->startedAgent();
        $timestamp = $this->rotate('2026-08-01-00-00-00');
        $this->write('agent-hilos_logs.log', 40);
        $agent->walkStore($this->t0 + 60);

        $this->removeTree($this->batchPath('2026-08-01-00-00-00'));
        $agent->walkStore($this->t0 + self::PAST_THE_DAY_WINDOW_SECONDS);

        $this->assertSame(40, $this->keyBytes($agent, 'agent-hilos_logs.log'));
        $this->assertSame([$timestamp], $agent->lastDelta()?->vanishedBatchTimestamps);
        $this->assertSame([], $agent->lastDelta()?->grownKeys);
        // The 40 bytes written into the new live file are growth; the batch that went away is not
        // an anti-growth of 300, and the day figure counts the one without the other.
        $this->assertSame(40, $agent->index()->growthBytesPerDay['agent-hilos_logs.log']);
    }

    public function testAVanishedKeyTakesItsGrowthWindowWithIt(): void
    {
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();
        $this->write('agent-hilos_logs.log', 900);
        $agent->walkStore($this->t0 + self::PAST_THE_DAY_WINDOW_SECONDS);
        $this->assertSame(800, $agent->index()->growthBytesPerDay['agent-hilos_logs.log']);

        unlink($this->dir . DIRECTORY_SEPARATOR . 'agent-hilos_logs.log');
        $agent->walkStore($this->t0 + self::PAST_THE_DAY_WINDOW_SECONDS + 60);
        $this->assertSame([], $agent->index()->growthBytesPerDay);

        // Reborn under the same name, the key starts a new series rather than inheriting the old
        // counter - which is what proves the window was dropped and not merely hidden.
        $this->write('agent-hilos_logs.log', 5);
        $agent->walkStore($this->t0 + self::PAST_THE_DAY_WINDOW_SECONDS + 120);

        $this->assertNull($agent->index()->growthBytesPerDay['agent-hilos_logs.log']);
    }

    public function testGrowthIsNullUntilTheWindowSpansADay(): void
    {
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();

        $this->write('agent-hilos_logs.log', 700);
        $agent->walkStore($this->t0 + 3600);
        $this->assertNull($agent->index()->growthBytesPerDay['agent-hilos_logs.log']);

        $agent->walkStore($this->t0 + self::PAST_THE_DAY_WINDOW_SECONDS);

        $this->assertSame(600, $agent->index()->growthBytesPerDay['agent-hilos_logs.log']);
    }

    public function testLiveWalkKeepsTheArchiveTheFullWalkFound(): void
    {
        $this->write('agent-hilos_logs.log', 300);
        $agent = $this->startedAgent();
        $this->rotate('2026-08-01-00-00-00');
        $agent->walkStore($this->t0 + 60);

        $this->write('agent-hilos_logs.log', 20);
        $agent->walkLiveFiles($this->t0 + 65);

        $this->assertSame(320, $this->keyBytes($agent, 'agent-hilos_logs.log'));
    }

    public function testLiveWalkFallsBackToTheFullOneWhenALiveKeyIsRotatedAway(): void
    {
        $this->write('agent-hilos_logs.log', 300);
        $agent = $this->startedAgent();

        $timestamp = $this->rotate('2026-08-01-00-00-00');
        $agent->walkLiveFiles($this->t0 + 5);

        // The live walk alone cannot see a batch; finding the live file gone, it re-read the archive.
        $this->assertSame([$timestamp], $agent->lastDelta()?->appearedBatchTimestamps);
        $this->assertSame(300, $this->keyBytes($agent, 'agent-hilos_logs.log'));
    }

    public function testUnreadableStoreIsAvailableFalseWithEmptyProjections(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        $agent = new LogStoreAgent();
        $agent->onStart();

        $index = $agent->index();
        $this->assertFalse($index->available);
        $this->assertSame([], $index->keys);
        $this->assertSame([], $index->batches);
        $this->assertSame([], $index->growthBytesPerDay);
        $this->assertTrue($agent->lastDelta()?->availabilityChanged);
    }

    public function testAnUnreadableStoreBreaksTheGrowthSeries(): void
    {
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();
        $this->write('agent-hilos_logs.log', 900);
        $agent->walkStore($this->t0 + self::PAST_THE_DAY_WINDOW_SECONDS);
        $this->assertSame(800, $agent->index()->growthBytesPerDay['agent-hilos_logs.log']);

        // The directory goes out of reach and comes back: the daemon restarts without the path and
        // then with it again, which is the only way an agent's reader is rebuilt.
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        $agent->onStart();
        $this->assertFalse($agent->index()->available);

        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        $agent->onStart();
        $agent->walkStore($this->t0 + 2 * self::PAST_THE_DAY_WINDOW_SECONDS);

        // The series restarted at the recovery, so the day figure counts what was written since -
        // nothing - instead of carrying the 800 across a gap nobody was measuring.
        $this->assertSame(0, $agent->index()->growthBytesPerDay['agent-hilos_logs.log']);
    }

    /**
     * The node owns the files, so the node is the judge of them (HIL-871): the verdict rides out
     * with the index it was measured against, and the screen draws what arrived.
     */
    public function testTheWalkPublishesTheRetentionVerdictWithTheIndex(): void
    {
        $this->evictAnythingOlderThanASecond();
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();
        $this->assertSame([], $agent->index()->dueBatchTimestamps, 'An empty archive recommends nothing');

        $timestamp = $this->rotate('2026-08-01-00-00-00');
        $agent->walkStore($this->t0 + 60);

        $this->assertSame([$timestamp], $agent->index()->dueBatchTimestamps);
        $this->assertSame(
            [],
            $agent->lastDelta()?->verdictChangedBatchTimestamps,
            'A batch that has just arrived is reported as arrived, not as re-judged',
        );
    }

    /**
     * The verdict is the one thing in the index that moves without a file moving: an administrator
     * edits the threshold, and the very next walk finds the same batches, the same weights and the
     * same markers with a different answer over them. Without its own axis the delta would be
     * judged empty and the frame carrying the new badges would never leave the node.
     */
    public function testAnEditedThresholdMovesTheVerdictWithoutMovingAFile(): void
    {
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();
        $timestamp = $this->rotate('2026-08-01-00-00-00');
        $agent->walkStore($this->t0 + 60);
        $this->assertSame([], $agent->index()->dueBatchTimestamps);

        $this->evictAnythingOlderThanASecond();
        $agent->walkStore($this->t0 + 120);

        $this->assertSame([$timestamp], $agent->index()->dueBatchTimestamps);
        $this->assertSame([$timestamp], $agent->lastDelta()?->verdictChangedBatchTimestamps);
        $this->assertSame([], $agent->lastDelta()?->appearedBatchTimestamps);
        $this->assertSame([], $agent->lastDelta()?->grownKeys);
        $this->assertFalse($agent->lastDelta()?->isEmpty(), 'A verdict that moved is worth a frame');
    }

    /**
     * And the crossing back, which is why the axis counts both directions: raising the keep-count
     * pulls a batch under protection again, and a one-way axis would leave the screen offering to
     * carry off what the node has already gone back to refusing.
     */
    public function testABatchPulledBackUnderProtectionMovesTheVerdictToo(): void
    {
        $this->evictAnythingOlderThanASecond();
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();
        $timestamp = $this->rotate('2026-08-01-00-00-00');
        $agent->walkStore($this->t0 + 60);
        $this->assertSame([$timestamp], $agent->index()->dueBatchTimestamps);

        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_MORE_THAN_THERE_ARE);
        $agent->walkStore($this->t0 + 120);

        $this->assertSame([], $agent->index()->dueBatchTimestamps);
        $this->assertSame([$timestamp], $agent->lastDelta()?->verdictChangedBatchTimestamps);
    }

    /**
     * A batch that goes away takes its verdict with it and is reported as gone, once: naming it on
     * the verdict axis as well would ask the screen to repaint a row that is no longer there.
     */
    public function testAVanishedBatchIsNotAlsoReportedAsReJudged(): void
    {
        $this->evictAnythingOlderThanASecond();
        $this->write('agent-hilos_logs.log', 100);
        $agent = $this->startedAgent();
        $timestamp = $this->rotate('2026-08-01-00-00-00');
        $agent->walkStore($this->t0 + 60);
        $this->assertSame([$timestamp], $agent->index()->dueBatchTimestamps);

        $this->removeTree($this->batchPath('2026-08-01-00-00-00'));
        $agent->walkStore($this->t0 + 120);

        $this->assertSame([$timestamp], $agent->lastDelta()?->vanishedBatchTimestamps);
        $this->assertSame([], $agent->lastDelta()?->verdictChangedBatchTimestamps);
    }

    public function testGrowthWindowNeverCountsAShrinkAsGrowth(): void
    {
        $window = new LogGrowthWindow();
        $window->addSample(self::T0, 1000);
        $window->addSample(self::T0 + 900, 200);
        $window->addSample(self::T0 + 1800, 350);

        $this->assertNull($window->growthPerDay(self::T0 + 1800));
        // 1000 → 200 contributes nothing, 200 → 350 contributes 150.
        $this->assertSame(150, $window->growthPerDay(self::T0 + self::PAST_THE_DAY_WINDOW_SECONDS));
    }

    public function testGrowthWindowResetStartsTheSeriesOver(): void
    {
        $window = new LogGrowthWindow();
        $window->addSample(self::T0, 100);
        $window->addSample(self::T0 + 900, 900);
        $this->assertSame(800, $window->growthPerDay(self::T0 + self::JUST_INSIDE_THE_DAY_WINDOW_SECONDS));

        $window->reset();
        $window->addSample(self::T0 + self::JUST_INSIDE_THE_DAY_WINDOW_SECONDS, 900);

        $this->assertNull($window->growthPerDay(self::T0 + self::JUST_INSIDE_THE_DAY_WINDOW_SECONDS));
    }

    /**
     * Agent started over the fixture directory, its baseline walk already taken.
     *
     * @return LogStoreAgent Started agent whose index holds the store as it stood at {@see self::T0}
     */
    private function startedAgent(): LogStoreAgent
    {
        $agent = new LogStoreAgent();
        $agent->onStart();

        return $agent;
    }

    /**
     * Puts the retention rule where every archived batch of these fixtures is a candidate.
     *
     * The count criterion is switched off and the age one set to a second, so what the rule
     * recommends is decided by the archive alone - the fixture batches are stamped 2026-08, which
     * is older than a second by any clock a test runs on.
     */
    private function evictAnythingOlderThanASecond(): void
    {
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_NO_BATCHES);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=' . self::EVICT_AFTER_A_SECOND);
    }

    /**
     * Writes one live log file of the given size, replacing whatever was there.
     *
     * @param string $name Basename to write
     * @param int $bytes Size in bytes
     */
    private function write(string $name, int $bytes): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, str_repeat('x', $bytes));
    }

    /**
     * Moves every live log into a timestamped batch, the way the rotator does.
     *
     * @param string $timestampDirName Batch folder name in {@see LogRotationConstants::TIMESTAMP_FORMAT}
     *
     * @return int Unix timestamp of the batch
     */
    private function rotate(string $timestampDirName): int
    {
        $path = $this->batchPath($timestampDirName);
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            $this->fail("Could not create fixture batch: {$path}");
        }
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $file) {
            rename($file, $path . DIRECTORY_SEPARATOR . basename($file));
        }

        $parsed = DateTimeImmutable::createFromFormat(LogRotationConstants::TIMESTAMP_FORMAT, $timestampDirName);
        if ($parsed === false) {
            $this->fail("Fixture batch name does not parse: {$timestampDirName}");
        }

        return $parsed->getTimestamp();
    }

    /**
     * Absolute path of one batch folder under the fixture archive.
     *
     * @param string $timestampDirName Batch folder name
     *
     * @return string Absolute path
     */
    private function batchPath(string $timestampDirName): string
    {
        return $this->dir
            . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
            . DIRECTORY_SEPARATOR . $timestampDirName;
    }

    /**
     * One key of the agent's current index.
     *
     * @param LogStoreAgent $agent Agent to read
     * @param string $name Key basename
     *
     * @return ?LogKeySummary Summary of that key, or null when the index does not hold it
     */
    private function key(LogStoreAgent $agent, string $name): ?LogKeySummary
    {
        foreach ($agent->index()->keys as $key) {
            if ($key->key === $name) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Total weight of one key in the agent's current index.
     *
     * @param LogStoreAgent $agent Agent to read
     * @param string $name Key basename
     *
     * @return ?int Total bytes, or null when the index does not hold the key
     */
    private function keyBytes(LogStoreAgent $agent, string $name): ?int
    {
        return $this->key($agent, $name)?->totalBytes;
    }

    /**
     * Recursively removes a directory tree.
     *
     * @param string $path Directory or file to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }
}
