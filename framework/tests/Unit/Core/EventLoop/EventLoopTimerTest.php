<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\EventLoop;

use Hilos\Core\EventLoop\EventLoop;
use PHPUnit\Framework\TestCase;
use Socket;
use WeakReference;

/** Native deadlines are brought to now; future deadlines are never waited out. */
final class EventLoopTimerTest extends TestCase
{
    private EventLoop $loop;

    /** @var list<Socket> Sockets closed after the loop releases their watches */
    private array $sockets = [];

    /** Fires once, removes the due deadline before the owner, and allows explicit rearming. */
    public function testOneShotRemovesItsDeadlineBeforeTheOwnerRearms(): void
    {
        $calls = 0;
        $deadlines = [];
        $timer = null;
        $timer = $this->loop->createTimer(function () use (&$calls, &$deadlines, &$timer): void {
            $deadlines[] = $timer->getDeadline();
            if (++$calls === 1) {
                $timer->armAt(hrtime(true) / 1e9 - 1.0);
            }
        });
        $timer->armAt(hrtime(true) / 1e9 - 1.0);
        $this->loop->runOnce();
        $this->assertSame(1, $calls);
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertSame(2, $calls);
        $this->assertSame([null, null], $deadlines);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Null cancels an armed event, and a subsequent arm creates only the new firing. */
    public function testCancelAndRearm(): void
    {
        $calls = 0;
        $timer = $this->loop->createTimer(function () use (&$calls): void {
            ++$calls;
        });
        $timer->armAt(hrtime(true) / 1e9 - 1.0);
        $timer->armAt(null);
        $this->loop->tick();
        $this->assertSame(0, $calls);
        $this->assertNull($timer->getDeadline());

        $timer->armAt(hrtime(true) / 1e9 + 3600.0);
        $timer->armAt(hrtime(true) / 1e9 - 1.0);
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertSame(1, $calls);
    }

    /** Already-ready I/O wakes the base without expiring or moving a future deadline. */
    public function testFutureDeadlineSurvivesUnrelatedIoAndUnchangedArming(): void
    {
        $calls = 0;
        $reads = 0;
        $timer = $this->loop->createTimer(function () use (&$calls): void {
            ++$calls;
        });
        $deadline = hrtime(true) / 1e9 + 3600.0;
        $timer->armAt($deadline);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets));
        $this->loop->registerRead($this->sockets[0], function () use (&$reads): void {
            socket_read($this->sockets[0], 1);
            ++$reads;
        });
        for ($iteration = 0; $iteration < 3; ++$iteration) {
            socket_write($this->sockets[1], 'x');
            $this->loop->runOnce();
            $timer->armAt($deadline);
            $this->assertSame($deadline, $timer->getDeadline());
            $this->assertSame(0, $calls);
        }
        $this->assertSame(3, $reads);
        $timer->armAt(hrtime(true) / 1e9 - 1.0);
        $this->loop->runOnce();
        $this->assertSame(1, $calls);
    }

    /** The native timer activates behind an existing ready owner, which cancels its queued generation. */
    public function testActivatedCallbackCannotSurviveCancellationAndRearming(): void
    {
        $calls = 0;
        $activationObserved = false;
        $timer = $this->loop->createTimer(function () use (&$calls): void {
            ++$calls;
        });
        $timer->armAt(hrtime(true) / 1e9 - 1.0);
        $this->loop->createReadyWork(function () use ($timer, &$activationObserved): void {
            $activationObserved = $timer->getDeadline() === null;
            $timer->armAt(null);
            $timer->armAt(hrtime(true) / 1e9 - 1.0);
        })->markReady();

        $this->loop->runOnce();
        $this->assertTrue($activationObserved, 'native activation must precede the cancellation');
        $this->assertSame(0, $calls, 'the old ready generation must not call the owner');
        $this->loop->runOnce();
        $this->loop->tick();
        $this->assertSame(1, $calls);
    }

    /** Several timer owners keep their portions, even when one queues a continuation. */
    public function testSeveralOwnersKeepAllWorkAndFifoContinuations(): void
    {
        $seen = [];
        $continuation = $this->loop->createReadyWork(function () use (&$seen): void {
            $seen[] = 'continuation';
        });
        for ($owner = 0; $owner < 4; ++$owner) {
            $this->loop->createTimer(function () use ($owner, &$seen, $continuation): void {
                $seen[] = $owner;
                $continuation->markReady();
            })->armAt(hrtime(true) / 1e9 - 1.0);
        }
        $this->loop->runOnce();
        $this->assertCount(4, $seen);
        $owners = $seen;
        sort($owners);
        $this->assertSame([0, 1, 2, 3], $owners);
        $this->assertTrue($this->loop->hasReadyWork());
        $expected = [...$seen, 'continuation'];
        $this->loop->runOnce();
        $this->assertSame($expected, $seen);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Cleanup cancels armed and already-activated owners and releases captured objects immediately. */
    public function testCloseReleasesTimersAndPendingOwners(): void
    {
        $owner = new class {
            public int $calls = 0;
        };
        $reference = WeakReference::create($owner);
        $armed = $this->loop->createTimer(function () use ($owner): void {
            ++$owner->calls;
        });
        $due = $this->loop->createTimer(function () use ($owner): void {
            ++$owner->calls;
        });
        unset($owner);
        $armed->armAt(hrtime(true) / 1e9 + 3600.0);
        $due->armAt(hrtime(true) / 1e9 - 1.0);
        $activated = false;
        $this->loop->createReadyWork(function () use ($due, &$activated): void {
            $activated = $due->getDeadline() === null;
            $this->loop->close();
        })->markReady();
        $this->loop->runOnce();
        $this->assertTrue($activated);
        $this->assertNull($reference->get(), 'no timer callback may retain its detached owner');
        $this->assertNull($armed->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
        $armed->close();
        $due->close();
        $this->loop->cleanup();
        $this->loop->tick();
    }

    /** Creates the real native loop for each independent owner lifecycle. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new EventLoop();
    }

    /** Releases events before closing the sockets they refer to. */
    protected function tearDown(): void
    {
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        parent::tearDown();
    }
}
