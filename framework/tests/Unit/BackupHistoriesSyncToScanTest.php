<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Actions\Collection\BackupHistoriesActions;
use Hilos\Runtime\View\Actions\Item\BackupHistoryActions;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a scan on one node does to the index rows it did not find (HIL-940).
 *
 * Storage is a local directory, so a scan speaks for one node's disk only. Before this leaf every
 * row the scan missed was forgotten, which meant that the agent moving to another node erased the
 * archive list of the node it left - on a screen where an empty list is exactly what a fresh
 * installation looks like. These cases pin the three branches that replaced it: a found row is
 * stamped with the scanning node, a missed row of this node (or of no node) is forgotten, and a
 * missed row of another node is kept and marked out of reach.
 */
final class BackupHistoriesSyncToScanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$rt = new BackupHistoriesSyncToScanTestRtContext();
        Hilos::$rt->mountFeatureCollection(StateBackupHistory::RT_COLLECTION, StateBackupHistories::init());
        Hilos::$rt->setRepresent(
            StateBackupHistory::RT_COLLECTION,
            BackupHistories::class,
            BackupHistoriesActions::class,
            BackupHistoryActions::class,
        );
        RtTruthSourceRegistry::registerDaemon(StateBackupHistory::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateBackupHistory::RT_COLLECTION);
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testAFoundArchiveIsStampedWithTheScanningNodeAndIsReachable(): void
    {
        $changes = $this->histories()->actions->syncToScan([$this->metadata('a')], 'm1');

        $this->assertSame(1, $changes);
        $this->assertSame('m1', $this->histories()['a']?->nodeId);
        $this->assertTrue($this->histories()['a']?->reachable);
    }

    public function testAScanOnAnotherNodeKeepsTheArchivesOfTheNodeItLeftAndMarksThemOutOfReach(): void
    {
        $this->histories()->actions->syncToScan([$this->metadata('left-behind')], 'm1');

        // The agent failed over: its new directory holds only what it took since.
        $changes = $this->histories()->actions->syncToScan([$this->metadata('taken-here')], 'm2');

        $this->assertSame(2, $changes, 'One row marked, one row created');
        $kept = $this->histories()['left-behind'];
        $this->assertNotNull($kept, 'The archive is still on the disk of m1; the list must not lose it');
        $this->assertFalse($kept->reachable);
        $this->assertSame('m1', $kept->nodeId, 'Where an archive lies never changes');
        $this->assertTrue($this->histories()['taken-here']?->reachable);
        $this->assertSame('m2', $this->histories()['taken-here']?->nodeId);
    }

    public function testARowAlreadyOutOfReachIsNotCountedAgain(): void
    {
        $this->histories()->actions->syncToScan([$this->metadata('left-behind')], 'm1');
        $this->histories()->actions->syncToScan([], 'm2');

        // The periodic pass runs every few minutes forever; a mark that did not flip is no change.
        $this->assertSame(0, $this->histories()->actions->syncToScan([], 'm2'));
    }

    public function testAScanOnTheOwningNodeStillForgetsADeletedArchive(): void
    {
        $this->histories()->actions->syncToScan([$this->metadata('a'), $this->metadata('b')], 'm1');

        $changes = $this->histories()->actions->syncToScan([$this->metadata('a')], 'm1');

        $this->assertSame(1, $changes);
        $this->assertNull($this->histories()['b'], 'The archive is really gone from this disk');
        $this->assertTrue($this->histories()['a']?->reachable);
    }

    public function testWithoutClusteringAMissedArchiveIsForgottenAsBefore(): void
    {
        $this->histories()->actions->syncToScan([$this->metadata('a'), $this->metadata('b')], null);

        $changes = $this->histories()->actions->syncToScan([$this->metadata('a')], null);

        $this->assertSame(1, $changes);
        $this->assertNull($this->histories()['b']);
        $this->assertNull($this->histories()['a']?->nodeId);
        $this->assertTrue($this->histories()['a']?->reachable);
    }

    public function testARowNamingNoNodeIsForgottenByAClusteredScanThatMissesIt(): void
    {
        // Written before the installation joined a cluster: it lies in the scanning agent's own
        // directory, so a scan that does not find it means the archive is gone.
        $this->histories()->actions->syncToScan([$this->metadata('legacy')], null);

        $changes = $this->histories()->actions->syncToScan([], 'm1');

        $this->assertSame(1, $changes);
        $this->assertNull($this->histories()['legacy']);
    }

    public function testARowNamingNoNodeIsStampedByTheFirstClusteredScanThatFindsIt(): void
    {
        // The single-node installation that later joins a cluster heals rather than losing its history.
        $this->histories()->actions->syncToScan([$this->metadata('legacy')], null);

        $changes = $this->histories()->actions->syncToScan([$this->metadata('legacy')], 'm1');

        $this->assertSame(1, $changes);
        $this->assertSame('m1', $this->histories()['legacy']?->nodeId);
        $this->assertTrue($this->histories()['legacy']?->reachable);
    }

    public function testTheAgentComingBackReachesItsArchivesAgainAndLosesTheOnesItTookAway(): void
    {
        $this->histories()->actions->syncToScan([$this->metadata('home')], 'm1');
        $this->histories()->actions->syncToScan([$this->metadata('away')], 'm2');

        // Moved back: the scan finds the old archive and misses the one taken on m2.
        $changes = $this->histories()->actions->syncToScan([$this->metadata('home')], 'm1');

        $this->assertSame(2, $changes);
        $this->assertTrue($this->histories()['home']?->reachable);
        $this->assertFalse($this->histories()['away']?->reachable);
        $this->assertSame('m2', $this->histories()['away']?->nodeId);
    }

    /**
     * @return BackupHistories The mounted backup index view
     */
    private function histories(): BackupHistories
    {
        $view = Hilos::$rt?->hilosBackupHistories;

        return $view instanceof BackupHistories
            ? $view
            : throw new RuntimeException('The backup index is not mounted.');
    }

    /**
     * @param string $id Backup id
     * @return BackupMetadata Minimal successful sidecar the scan found
     */
    private function metadata(string $id): BackupMetadata
    {
        return new BackupMetadata(
            id: $id,
            createdAt: '2026-09-16T00:00:00+00:00',
            env: 'test',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 0,
            durationSeconds: 0,
            keep: false,
            status: BackupStatus::SUCCESS,
        );
    }
}

/**
 * Runtime context that registers no project state: the case mounts the backup index itself.
 */
final class BackupHistoriesSyncToScanTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
