<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Status\Exception\StatusException;
use Hilos\Status\StatusMaintenanceObservation;
use Hilos\Status\StatusMaintenanceObservationStore;
use Hilos\Status\StatusPublicMaintenancePeriod;
use Hilos\Status\StatusPublicSnapshot;
use Hilos\Status\StatusPublicWork;
use Hilos\Tests\Fixtures\StatusPublicVolume;
use PHPUnit\Framework\TestCase;

/** Real private transit and the distinction between exact and observed completion. */
final class StatusMaintenanceObservationTest extends TestCase
{
    public function testContinuousAndGappedCompletionRetainIdentityAndAnnouncement(): void
    {
        $work = new StatusPublicWork(str_repeat('a', 32), 'Upgrade', 'Public text', ['service'], 1000, 2000);
        $start = StatusMaintenanceObservation::start(1500, 1600, true, $work);
        $busy = $start->observe(3000);
        self::assertSame($start->period->id, $busy->period->id);
        self::assertSame($work, $busy->work);
        self::assertSame(1500, $busy->period->startedAt);
        self::assertNull($busy->period->completionObservedAt);
        self::assertSame(3100, $busy->complete(3100, true)->period->endedAt);
        $gap = $busy->complete(5000, false);
        self::assertNull($gap->period->endedAt);
        self::assertSame(5000, $gap->period->completionObservedAt);
        self::assertSame(3000, $gap->period->lastObservedAt);
        self::assertSame($gap, $gap->complete(6000, true));
        self::assertSame(
            $gap->toArray(),
            StatusMaintenanceObservation::fromJson(json_encode($gap->toArray(), JSON_THROW_ON_ERROR))->toArray(),
        );
    }

    public function testUnsortedPublicV2AndTransitKeepSetIdentityWithoutAcceptingAnotherSet(): void
    {
        $work = new StatusPublicWork(str_repeat('a', 32), 'Upgrade', null, ['web', 'api'], 1000, 2000);
        $period = new StatusPublicMaintenancePeriod(str_repeat('b', 32), $work->id, true, $work->title,
            ['api', 'web'], 1000, 1500, null, null);
        $data = (new StatusPublicSnapshot('unsorted-v2', 1500, 30, 120, [], [], [], [$work], [$period]))->toArray();
        $data['scheduledWorks'][0]['componentKeys'] = ['web', 'api'];
        $snapshot = StatusPublicSnapshot::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
        $observation = new StatusMaintenanceObservation($snapshot->maintenancePeriods[0], $snapshot->scheduledWorks[0]);
        $transit = $observation->toArray();
        $transit['period']['componentKeys'] = ['web', 'api'];
        $transit['work']['componentKeys'] = ['api', 'web'];
        $restored = StatusMaintenanceObservation::fromJson(json_encode($transit, JSON_THROW_ON_ERROR));
        self::assertSame(['api', 'web'], $work->componentKeys);
        self::assertSame($observation->toArray(), $restored->toArray());
        $transit['work']['componentKeys'] = ['web'];
        $this->expectException(StatusException::class);
        StatusMaintenanceObservation::fromJson(json_encode($transit, JSON_THROW_ON_ERROR));
    }

    public function testTransitCannotClaimUseOutsideItsSavedAnnouncementWindow(): void
    {
        $work = new StatusPublicWork(str_repeat('a', 32), 'Upgrade', null, ['service'], 1000, 2000);
        $transit = StatusMaintenanceObservation::start(1000, 1500, true, $work)->toArray();
        $transit['work']['startsAt'] = 1001;
        $this->expectException(StatusException::class);
        StatusMaintenanceObservation::fromJson(json_encode($transit, JSON_THROW_ON_ERROR));
    }

    public function testUnknownIsDistinctFromKnownUnannouncedAndWindowIsHalfOpen(): void
    {
        self::assertFalse(StatusMaintenanceObservation::start(1000, 1000, false, null)->period->announcementKnown);
        self::assertTrue(StatusMaintenanceObservation::start(1000, 1000, true, null)->period->announcementKnown);
        $work = new StatusPublicWork(str_repeat('a', 32), 'Upgrade', null, ['service'], 1000, 2000);
        self::assertSame($work->id, StatusMaintenanceObservation::start(1000, 1000, true, $work)->period->workId);
        $this->expectException(StatusException::class);
        StatusMaintenanceObservation::start(2000, 2000, true, $work);
    }

    public function testPrivateTransitIsAtomicBoundedAndChecksFileIdentity(): void
    {
        $volume = new StatusPublicVolume();
        try {
            $store = new StatusMaintenanceObservationStore();
            self::assertSame([], $store->discover());
            $observation = StatusMaintenanceObservation::start(1000, 1000, false, null);
            $store->save($observation);
            $store->save($observation->observe(2000));
            $name = $observation->period->id . '.json';
            $path = Hilos::$fs->status->getPath() . '/maintenance-observations';
            self::assertSame(2000, $store->load($name)->period->lastObservedAt);
            self::assertSame([$name], $store->discover());
            self::assertSame(0600, fileperms($path . '/' . $name) & 0777);
            self::assertSame(0700, fileperms($path) & 0777);
            $all = [$name];
            for ($index = 0; $index < 70; ++$index) {
                $another = StatusMaintenanceObservation::start(1000, 1000, false, null);
                $store->save($another);
                $all[] = $another->period->id . '.json';
            }
            $discovered = [];
            do {
                $portion = $store->discover();
                self::assertLessThanOrEqual(32, count($portion));
                array_push($discovered, ...$portion);
            } while ($store->isDiscovering());
            sort($all);
            sort($discovered);
            self::assertSame($all, $discovered);
            FsPath::write($path . '/' . str_repeat('b', 32) . '.json', json_encode($observation->toArray(), JSON_THROW_ON_ERROR));
            $this->expectException(StatusException::class);
            $store->load(str_repeat('b', 32) . '.json');
        } finally {
            $volume->close();
        }
    }

    public function testMalformedOrPrivateFieldsNeverEnterTransit(): void
    {
        $observation = StatusMaintenanceObservation::start(1000, 1000, false, null);
        foreach (['nodeId', 'operation', 'email', 'token', 'work_id'] as $privateField) {
            $data = $observation->toArray();
            $data['period'][$privateField] = 'private';
            try {
                StatusMaintenanceObservation::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
                self::fail('Unknown private field accepted.');
            } catch (StatusException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(StatusException::class);
        StatusMaintenanceObservation::fromJson('{broken');
    }

    public function testCommittedFileRemovalIsIdempotent(): void
    {
        $volume = new StatusPublicVolume();
        try {
            $store = new StatusMaintenanceObservationStore();
            $observation = StatusMaintenanceObservation::start(1000, 1000, false, null)->complete(2000, true);
            $store->save($observation);
            $store->remove($observation);
            $store->remove($observation);
            self::assertSame([], $store->discover());
        } finally {
            $volume->close();
        }
    }
}
