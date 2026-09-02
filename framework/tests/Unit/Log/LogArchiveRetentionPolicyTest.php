<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Log\LogArchiveRetentionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure log-archive retention predicate (HIL-381).
 *
 * Locks the union-keep semantics without any I/O: a batch is a candidate only when it is BOTH
 * outside the newest kept batches AND older than the max age; either threshold at 0 disables that
 * criterion and both at 0 yields no candidates. Ages are measured against an injected `$now`.
 *
 * The environment tests (HIL-682) lock the direction a typo must never take here: because a 0
 * threshold widens eviction instead of narrowing it, an unreadable value makes the whole policy
 * inert rather than disabling only its own criterion.
 */
final class LogArchiveRetentionPolicyTest extends TestCase
{
    /** Fixed reference instant (Unix seconds) the batch ages are measured against. */
    private const int NOW = 1_000_000;

    /** One day in seconds, the unit the age fixtures are built from. */
    private const int DAY = 86_400;

    protected function tearDown(): void
    {
        foreach ([
            EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES,
            EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS,
        ] as $key) {
            putenv($key->name);
        }

        parent::tearDown();
    }

    public function testBothThresholdsZeroYieldsNoCandidates(): void
    {
        $policy = new LogArchiveRetentionPolicy(0, 0);

        $batches = $this->batchesAgedDays([0, 10, 100, 1000]);
        $this->assertSame([], $policy->selectEvictionCandidates($batches, self::NOW));
    }

    public function testCountOnlyKeepsNewestAndEvictsTheRest(): void
    {
        $policy = new LogArchiveRetentionPolicy(2, 0);

        // Ages 1,3,5,7 days -> newest two (1d, 3d) kept, older two are candidates.
        $batches = $this->batchesAgedDays([7, 5, 3, 1]);
        $this->assertSame(
            [$this->agedDays(7), $this->agedDays(5)],
            $policy->selectEvictionCandidates($batches, self::NOW),
        );
    }

    public function testCountOnlyEvictsNothingWhenWithinKeep(): void
    {
        $policy = new LogArchiveRetentionPolicy(5, 0);

        $batches = $this->batchesAgedDays([1, 2, 3]);
        $this->assertSame([], $policy->selectEvictionCandidates($batches, self::NOW));
    }

    public function testAgeOnlyEvictsOnlyBatchesOlderThanMax(): void
    {
        $policy = new LogArchiveRetentionPolicy(0, 30 * self::DAY);

        // Only batches strictly older than 30 days qualify; exactly 30 days does not.
        $batches = $this->batchesAgedDays([10, 30, 31, 100]);
        $this->assertSame(
            [$this->agedDays(31), $this->agedDays(100)],
            $policy->selectEvictionCandidates($batches, self::NOW),
        );
    }

    public function testBothActiveRequiresOutsideNewestAndOlderThanMax(): void
    {
        $policy = new LogArchiveRetentionPolicy(2, 30 * self::DAY);

        // Newest two (5d, 20d) are count-protected; of the rest only those older than 30d qualify,
        // so the 25-day batch survives on age even though it is outside the newest two.
        $batches = $this->batchesAgedDays([5, 20, 25, 40, 60]);
        $this->assertSame(
            [$this->agedDays(40), $this->agedDays(60)],
            $policy->selectEvictionCandidates($batches, self::NOW),
        );
    }

    public function testEmptyInputYieldsNoCandidates(): void
    {
        $policy = new LogArchiveRetentionPolicy(20, 2_592_000);

        $this->assertSame([], $policy->selectEvictionCandidates([], self::NOW));
    }

    public function testEqualTimestampsProtectByOriginalOrder(): void
    {
        $policy = new LogArchiveRetentionPolicy(1, 0);

        // Three batches at the same instant: the count exemption is resolved deterministically by
        // original order, so the first stays and the rest become candidates.
        $timestamp = $this->agedDays(50);
        $this->assertSame(
            [$timestamp, $timestamp],
            $policy->selectEvictionCandidates([$timestamp, $timestamp, $timestamp], self::NOW),
        );
    }

    public function testPolicyBuiltFromValuesCarriesThemAsGiven(): void
    {
        // The shape the settings resolver builds: values in, no environment read.
        $policy = new LogArchiveRetentionPolicy(7, 604_800);

        $this->assertSame(7, $policy->keepBatches);
        $this->assertSame(604_800, $policy->maxAgeSeconds);
    }

    public function testUnreadableKeepCountMakesTheWholePolicyInert(): void
    {
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=twenty');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=' . 30 * self::DAY);

        $policy = LogArchiveRetentionPolicy::fromEnv();

        // Not "the count criterion is off": that would hand the pruner every batch older than the age.
        $this->assertSame(0, $policy->keepBatches);
        $this->assertSame(0, $policy->maxAgeSeconds);
        $this->assertSame([], $policy->selectEvictionCandidates($this->batchesAgedDays([0, 10, 100, 1000]), self::NOW));
        $this->assertSame([EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name], array_keys($policy->unreadable));
    }

    public function testUnreadableMaxAgeMakesTheWholePolicyInert(): void
    {
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=2');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=a month');

        $policy = LogArchiveRetentionPolicy::fromEnv();

        $this->assertSame(0, $policy->keepBatches);
        $this->assertSame(0, $policy->maxAgeSeconds);
        $this->assertSame([], $policy->selectEvictionCandidates($this->batchesAgedDays([0, 10, 100, 1000]), self::NOW));
        $this->assertSame([EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name], array_keys($policy->unreadable));
    }

    public function testEnvironmentThatAnswersLeavesNothingUnreadable(): void
    {
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=2');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=' . 30 * self::DAY);

        $policy = LogArchiveRetentionPolicy::fromEnv();

        $this->assertSame([], $policy->unreadable);
        $this->assertSame(2, $policy->keepBatches);
        $this->assertSame(30 * self::DAY, $policy->maxAgeSeconds);
    }

    /**
     * Builds a batch timestamp `$days` days before {@see NOW}.
     *
     * @param int $days Age of the batch in days
     * @return int Batch timestamp in Unix seconds
     */
    private function agedDays(int $days): int
    {
        return self::NOW - $days * self::DAY;
    }

    /**
     * Builds a list of batch timestamps from their ages in days, preserving order.
     *
     * @param list<int> $daysList Batch ages in days
     * @return list<int> Batch timestamps in Unix seconds
     */
    private function batchesAgedDays(array $daysList): array
    {
        return array_map(fn(int $days): int => $this->agedDays($days), $daysList);
    }
}
