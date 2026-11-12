<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Status\Exception\StatusException;
use Hilos\Status\StatusSilenceObservation;
use Hilos\Status\StatusSilenceObservationStore;
use Hilos\Tests\Fixtures\StatusPublicVolume;
use PHPUnit\Framework\TestCase;

/** Isolated real volume checks of transit only; publisher receipt and cluster recovery have separate owners. */
final class StatusSilenceObservationStoreTest extends TestCase
{
    private StatusPublicVolume $volume;

    /** Mounts an isolated CLUSTER Status volume. */
    protected function setUp(): void
    {
        $this->volume = new StatusPublicVolume();
    }

    /** Removes all fixture files and restores the prior filesystem context. */
    protected function tearDown(): void
    {
        $this->volume->close();
    }

    public function testAtomicOpenAndClosedRoundTripRecoversTheSameIdentity(): void
    {
        $store = new StatusSilenceObservationStore();
        self::assertSame([], $store->discover());
        self::assertFalse($store->isDiscovering());
        $open = StatusSilenceObservation::start(-1000);
        $name = $open->id . '.json';
        $store->save($open);
        $restarted = new StatusSilenceObservationStore();
        self::assertSame([$name], $restarted->discover());
        self::assertSame($open->toArray(), $restarted->load($name)->toArray());
        $closed = $restarted->load($name)->complete(-1);
        $restarted->save($closed);
        $restarted->save($closed);
        self::assertSame($closed->toArray(), (new StatusSilenceObservationStore())->load($name)->toArray());
        self::assertSame(['formatVersion' => 1, 'period' => ['id' => $open->id, 'startedAt' => -1000, 'endedAt' => -1]],
            json_decode(FsPath::read($this->path() . '/' . $name), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([$name], FsPath::entries($this->path()), 'Only the complete final file remains.');
        self::assertSame(0700, fileperms($this->path()) & 0777);
        self::assertSame(0600, fileperms($this->path() . '/' . $name) & 0777);
        self::assertSame(['silence-observations'], Hilos::$fs->status->entries());
    }

    public function testBadFilesAndIdentityMismatchesRemainAvailableForRetry(): void
    {
        $store = new StatusSilenceObservationStore();
        $open = StatusSilenceObservation::start(1000);
        $store->save($open);
        $name = $open->id . '.json';
        $path = $this->path() . '/' . $name;
        foreach ([
            '{broken',
            '{"formatVersion":2,"period":{}}',
            json_encode(['formatVersion' => 1, 'period' => $open->toArray()['period'], 'nodeId' => 'private-node'], JSON_THROW_ON_ERROR),
            json_encode((new StatusSilenceObservation(str_repeat('b', 32), 1000, null))->toArray(), JSON_THROW_ON_ERROR),
            str_repeat(' ', StatusSilenceObservationStore::MAX_FILE_BYTES + 1),
        ] as $corrupt) {
            FsPath::write($path, $corrupt);
            $this->assertRefused(fn() => $store->load($name));
            $this->assertRefused(fn() => $store->save($open->complete(2000)));
            $this->assertRefused(fn() => $store->remove($open->complete(2000)));
            self::assertSame($corrupt, FsPath::read($path));
        }
        foreach (['../' . $name, '.' . $name, strtoupper($name), 'not-an-id.json', $open->id . '.tmp'] as $badName) {
            $this->assertRefused(fn() => $store->load($badName));
        }
        FsPath::write($path, json_encode($open->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame($open->toArray(), $store->load($name)->toArray());
    }

    public function testSymlinksToFilesAndDirectoriesAreNeverTrusted(): void
    {
        $store = new StatusSilenceObservationStore();
        $closed = StatusSilenceObservation::start(1000)->complete(2000);
        $store->save($closed);
        $name = $closed->id . '.json';
        $file = $this->path() . '/' . $name;
        $heldFile = Hilos::$fs->status->getPath() . '/held.json';
        FsPath::move($file, $heldFile);
        foreach ([$heldFile, Hilos::$fs->status->getPath() . '/absent.json'] as $target) {
            self::assertTrue(symlink($target, $file));
            try {
                self::assertSame([], $store->discover());
                $this->assertRefused(fn() => $store->load($name));
                $this->assertRefused(fn() => $store->save($closed));
                $this->assertRefused(fn() => $store->remove($closed));
                self::assertTrue(is_link($file));
                self::assertSame($closed->toArray(), StatusSilenceObservation::fromJson(FsPath::read($heldFile))->toArray());
            } finally {
                unlink($file);
            }
        }
        FsPath::move($heldFile, $file);
        $heldDirectory = Hilos::$fs->status->getPath() . '/held-directory';
        FsPath::move($this->path(), $heldDirectory);
        self::assertTrue(symlink($heldDirectory, $this->path()));
        try {
            $this->assertRefused(fn() => $store->discover());
            self::assertFalse($store->isDiscovering());
            $this->assertRefused(fn() => $store->load($name));
            $this->assertRefused(fn() => $store->save($closed));
            $this->assertRefused(fn() => $store->remove($closed));
            self::assertSame($closed->toArray(), StatusSilenceObservation::fromJson(FsPath::read($heldDirectory . '/' . $name))->toArray());
        } finally {
            unlink($this->path());
            FsPath::move($heldDirectory, $this->path());
        }
    }

    public function testDiscoveryCountsForeignAndDamagedEntriesWithinItsBudget(): void
    {
        $store = new StatusSilenceObservationStore();
        FsPath::ensureDirectory($this->path());
        for ($index = 0; $index < 70; ++$index) {
            FsPath::write($this->path() . '/foreign-' . $index . '.tmp', 'ignored');
        }
        $expected = [];
        for ($index = 0; $index < 40; ++$index) {
            $observation = StatusSilenceObservation::start(1000);
            $store->save($observation);
            $expected[] = $observation->id . '.json';
        }
        $damagedName = str_repeat('b', 32) . '.json';
        FsPath::write($this->path() . '/' . $damagedName, '{broken');
        $expected[] = $damagedName;
        $discovered = [];
        $passes = 0;
        do {
            $portion = $store->discover();
            self::assertLessThanOrEqual(32, count($portion));
            array_push($discovered, ...$portion);
            ++$passes;
            self::assertLessThanOrEqual(4, $passes, 'The sweep finishes without restarting its cursor.');
        } while ($store->isDiscovering());
        self::assertSame(4, $passes, 'Foreign entries count against the 32-entry budget.');
        sort($expected);
        sort($discovered);
        self::assertSame($expected, $discovered);
        self::assertFalse($store->isDiscovering());
        $this->assertRefused(fn() => $store->load($damagedName));
        self::assertLessThanOrEqual(32, count($store->discover()));
        self::assertTrue($store->isDiscovering(), 'A completed sweep can start again.');
    }

    public function testFailedSaveCanRetryWithoutLosingThePreviousObservation(): void
    {
        $store = new StatusSilenceObservationStore();
        $open = StatusSilenceObservation::start(1000);
        $closed = $open->complete(2000);
        $name = $open->id . '.json';
        $store->save($open);
        $held = Hilos::$fs->status->getPath() . '/held-directory';
        FsPath::move($this->path(), $held);
        FsPath::write($this->path(), 'unavailable directory');
        try {
            $this->assertRefused(fn() => $store->save($closed));
            $this->assertRefused(fn() => $store->discover());
            self::assertFalse($store->isDiscovering());
            self::assertSame($open->toArray(), StatusSilenceObservation::fromJson(FsPath::read($held . '/' . $name))->toArray());
        } finally {
            FsPath::delete($this->path());
            FsPath::move($held, $this->path());
        }
        self::assertSame($open->toArray(), $store->load($name)->toArray());
        $store->save($closed);
        self::assertSame($closed->toArray(), $store->load($name)->toArray());
        $this->assertRefused(fn() => $store->save($open));
        $this->assertRefused(fn() => $store->save(new StatusSilenceObservation($open->id, 999, 2000)));
        self::assertSame($closed->toArray(), $store->load($name)->toArray());
        self::assertSame([$name], FsPath::entries($this->path()));
    }

    public function testRemovalRequiresTheExactClosedCommittedFacts(): void
    {
        $store = new StatusSilenceObservationStore();
        $open = StatusSilenceObservation::start(1000);
        $closed = $open->complete(2000);
        $name = $open->id . '.json';
        $store->save($open);
        $this->assertRefused(fn() => $store->remove($open));
        $this->assertRefused(fn() => $store->remove($closed));
        self::assertSame($open->toArray(), $store->load($name)->toArray());
        $store->save($closed);
        $this->assertRefused(fn() => $store->remove(new StatusSilenceObservation($open->id, 999, 2000)));
        $this->assertRefused(fn() => $store->remove($open->complete(2001)));
        self::assertSame($closed->toArray(), $store->load($name)->toArray());
        $store->remove($closed);
        $store->remove($closed);
        self::assertSame([], $store->discover());
    }

    /** @return string Private fixture directory under the current Status volume */
    private function path(): string
    {
        return Hilos::$fs->status->getPath() . '/' . StatusSilenceObservationStore::DIRECTORY;
    }

    /** @param callable(): mixed $operation Operation expected to refuse unsafe transit */
    private function assertRefused(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe silence transit was accepted.');
        } catch (StatusException) {
            $this->addToAssertionCount(1);
        }
    }
}
