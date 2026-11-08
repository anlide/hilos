<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\EventLoop;

use Hilos\Core\EventLoop\EventLoop;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/** Ready owners take bounded FIFO portions without turning held work into polling. */
final class ReadyWorkTest extends TestCase
{
    private EventLoop $loop;

    /** Duplicate readiness coalesces and a self-queue cannot consume another portion in the same pass. */
    public function testDeduplicationAndSelfContinuationFairness(): void
    {
        $seen = [];
        $first = null;
        $first = $this->loop->createReadyWork(function () use (&$seen, &$first): void {
            $seen[] = 'first';
            $first->markReady();
            $this->loop->tick();
        });
        $second = $this->loop->createReadyWork(function () use (&$seen): void {
            $seen[] = 'second';
        });
        $first->markReady();
        $first->markReady();
        $second->markReady();
        $this->loop->runOnce();
        $this->assertSame(['first', 'second'], $seen);
        $this->assertTrue($this->loop->hasReadyWork());
        $this->loop->runOnce();
        $this->assertSame(['first', 'second', 'first'], $seen);
        $first->cancel();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A large finite queue yields, keeps FIFO, and neither loses nor repeats any owner. */
    public function testLargeReadyQueueIsBoundedAndPreservesFifo(): void
    {
        $seen = [];
        for ($owner = 0; $owner < 257; ++$owner) {
            $this->loop->createReadyWork(function () use ($owner, &$seen): void {
                $seen[] = $owner;
            })->markReady();
        }
        $this->loop->runOnce();
        $this->assertGreaterThan(0, count($seen));
        $this->assertLessThan(257, count($seen));
        $this->assertSame(range(0, count($seen) - 1), $seen);
        for ($pass = 0; $pass < 257 && $this->loop->hasReadyWork(); ++$pass) {
            $this->loop->runOnce();
        }
        $this->assertSame(range(0, 256), $seen);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A canceled successor requeued by a predecessor joins the next pass, not its former place. */
    public function testCancelRequeueAndCloseRemoveOldTickets(): void
    {
        $seen = [];
        $successor = $this->loop->createReadyWork(function () use (&$seen): void {
            $seen[] = 'successor';
        });
        $closed = $this->loop->createReadyWork(function () use (&$seen): void {
            $seen[] = 'closed';
        });
        $this->loop->createReadyWork(function () use ($successor, $closed, &$seen): void {
            $seen[] = 'first';
            $successor->cancel();
            $successor->markReady();
            $closed->close();
            $closed->markReady();
        })->markReady();
        $successor->markReady();
        $closed->markReady();
        $this->loop->runOnce();
        $this->assertSame(['first'], $seen);
        $this->loop->runOnce();
        $this->assertSame(['first', 'successor'], $seen);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** The caller's guard sees the original failure and a later pass can still run the queued remainder. */
    public function testOwnerFailureReachesGuardWithoutDiscardingRemainder(): void
    {
        $failure = new HilosException('owner portion failed');
        $seen = [];
        $this->loop->createReadyWork(function () use ($failure): void {
            throw $failure;
        })->markReady();
        $this->loop->createReadyWork(function () use (&$seen): void {
            $seen[] = 'next';
        })->markReady();
        try {
            $this->loop->runOnce();
            $this->fail('the manager guard must receive the owner failure');
        } catch (HilosException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame([], $seen);
        $this->assertTrue($this->loop->hasReadyWork());
        $this->loop->runOnce();
        $this->assertSame(['next'], $seen);
    }

    public function testSelfQueuedFailureRetiresOnlyTheFailedHandle(): void
    {
        $calls = 0;
        $work = null;
        $work = $this->loop->createReadyWork(function () use (&$work, &$calls): void {
            ++$calls;
            $work->markReady();
            throw new HilosException('self-queued owner failed');
        });
        $seen = [];
        $work->markReady();
        $this->loop->createReadyWork(static function () use (&$seen): void { $seen[] = 'healthy'; })->markReady();
        try {
            $this->loop->runOnce();
            $this->fail('the manager must receive the owner failure');
        } catch (HilosException) {
            $this->assertTrue($work->isClosed());
        }
        $work->markReady();
        $this->loop->runOnce();
        $this->assertSame(1, $calls);
        $this->assertSame(['healthy'], $seen);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Each scenario uses a fresh real native base. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new EventLoop();
    }

    /** No owner or native handle survives its scenario. */
    protected function tearDown(): void
    {
        $this->loop->cleanup();
        parent::tearDown();
    }
}
