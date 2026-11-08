<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Utils;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Utils\ClientReadFailureLog;
use Hilos\Utils\Logger;
use Hilos\Utils\RepeatedFailureWindows;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;

/** Real native readiness only cues the guarded summary phase; no blanket daemon pass runs here. */
final class ClientReadFailureLogNativeTest extends TestCase
{
    private EventLoop $loop;
    private DaemonManager $manager;
    private string $log;

    /** @var list<Socket> Ready I/O used instead of waiting out a future deadline */
    private array $sockets = [];

    /** Three warnings and node errors have no useful summary; the fourth keeps the first origin. */
    public function testUsefulDeadlineAndUnrelatedIo(): void
    {
        $this->bind();
        $this->assertNull($this->timer()->getDeadline());
        $origin = hrtime(true) / 1e9;
        $this->flood('browser', $origin, 3);
        ClientReadFailureLog::write('broken', ClientReadFailureLog::READER_TICK, new RuntimeException('broken'), $origin);
        $this->assertNull($this->timer()->getDeadline());
        $this->flood('browser', $origin + 1, 1);
        $deadline = $origin + 60;
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->flood('peer', $origin + 2, 4);
        $this->flood('browser', $origin + 3, 2);

        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets));
        $this->loop->registerRead($this->sockets[0], function (): void {
            socket_read($this->sockets[0], 1);
        });
        socket_write($this->sockets[1], 'x');
        $this->loop->runOnce();
        $this->invoke('dispatchClientReadSummary');
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->assertStringNotContainsString('Suppressed', $this->logged());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Aged business windows are armed on attach; native callbacks never write the summaries themselves. */
    public function testDueSummariesExecuteOnlyUnderGuardAndOnlyOnce(): void
    {
        $origin = hrtime(true) / 1e9 - 61;
        $this->flood('browser', $origin, 5);
        $this->flood('peer', $origin + 0.5, 4);
        $this->bind();
        $this->loop->runOnce();
        $this->assertStringNotContainsString('Suppressed', $this->logged());
        $this->invoke('dispatchClientReadSummary');
        $this->assertStringContainsString('Suppressed 2 more ' . InvalidJsonException::class . ' failures for browser', $this->logged());
        $this->assertStringContainsString('Suppressed 1 more ' . InvalidJsonException::class . ' failures for peer', $this->logged());
        $this->assertSame(2, substr_count($this->logged(), 'Suppressed'));
        $this->invoke('dispatchClientReadSummary');
        $this->loop->tick();
        $this->assertSame(2, substr_count($this->logged(), 'Suppressed'));
        $this->assertNull($this->timer()->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A failure reopening an expired key writes the old summary first, canceling its obsolete native callback. */
    public function testSummaryPrecedesReopeningWarning(): void
    {
        $this->flood('browser', hrtime(true) / 1e9 - 61, 4);
        $this->bind();
        ClientReadFailureLog::write('browser', ClientReadFailureLog::READER_TICK,
            new InvalidJsonException('reopened warning'), hrtime(true) / 1e9);
        $this->assertLessThan(strpos($this->logged(), 'reopened warning'), strpos($this->logged(), 'Suppressed'));
        $this->assertNull($this->timer()->getDeadline());
        $this->loop->tick();
        $this->invoke('dispatchClientReadSummary');
        $this->assertSame(1, substr_count($this->logged(), 'Suppressed'));
    }

    /** Reset cancels an activated generation; rebind retains the window and rejects an escaped old observer. */
    public function testActivatedResetRebindAndExactDetach(): void
    {
        $this->flood('browser', hrtime(true) / 1e9 - 61, 4);
        $this->bind();
        $oldWake = new ReflectionProperty(DaemonManager::class, 'clientReadSummaryWake')->getValue($this->manager);
        $activated = false;
        $this->loop->createReadyWork(function () use (&$activated): void {
            $activated = $this->timer()->getDeadline() === null;
            ClientReadFailureLog::reset();
        })->markReady();
        $this->loop->runOnce();
        $this->assertTrue($activated);
        $this->invoke('dispatchClientReadSummary');
        $this->assertStringNotContainsString('Suppressed', $this->logged());

        $this->flood('browser', hrtime(true) / 1e9 - 61, 4);
        $this->invoke('attachClientReadSummaryEventLoop');
        $currentWake = new ReflectionProperty(DaemonManager::class, 'clientReadSummaryWake')->getValue($this->manager);
        ClientReadFailureLog::removeSummaryWake($oldWake);
        $oldWake();
        $this->loop->runOnce();
        $this->invoke('dispatchClientReadSummary');
        $this->assertSame(1, substr_count($this->logged(), 'Suppressed'));
        $this->invoke('detachClientReadSummaryEventLoop');
        $currentWake();
        $this->loop->tick();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A throwing ready owner leaves this already-activated timer in the FIFO for the next guarded pass. */
    public function testAnotherOwnerFailureDoesNotLoseTheSummary(): void
    {
        $this->flood('browser', hrtime(true) / 1e9 - 61, 4);
        $this->bind();
        $this->loop->createReadyWork(static function (): void {
            throw new RuntimeException('other owner');
        })->markReady();
        try {
            $this->loop->runOnce();
            $this->fail('The other owner must throw.');
        } catch (RuntimeException $failure) {
            $this->assertSame('other owner', $failure->getMessage());
        }
        $this->loop->tick();
        $this->invoke('dispatchClientReadSummary');
        $this->assertSame(1, substr_count($this->logged(), 'Suppressed'));
    }

    /** The arithmetic reader ignores unsuppressed windows and keeps replaced summaries independent. */
    public function testReplacedWindowsAreUsefulNowWithoutBeingConsumed(): void
    {
        $windows = new RepeatedFailureWindows(1, 60.0);
        $windows->admits('quiet', 5.0);
        $this->assertNull($windows->nextSummaryAt(6.0));
        $windows->admits('loud', 10.0);
        $windows->admits('loud', 11.0);
        $this->assertSame(70.0, $windows->nextSummaryAt(12.0));
        $windows->admits('loud', 70.0);
        $windows->admits('loud', 71.0);
        $windows->admits('loud', 130.0);
        $this->assertSame(131.0, $windows->nextSummaryAt(131.0));
        $this->assertSame([
            ['key' => 'loud', 'held' => 1],
            ['key' => 'loud', 'held' => 1],
            ['key' => 'loud', 'held' => 0],
        ], $windows->closeKey('loud'));
        $this->assertTrue($windows->hasPendingWindows());
        $this->assertNull($windows->nextSummaryAt(131.0));
    }

    /** Installs native state without starting a daemon or another process. */
    protected function setUp(): void
    {
        $this->loop = new EventLoop();
        $this->manager = $this->getMockBuilder(DaemonManager::class)->disableOriginalConstructor()->getMock();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
        $this->log = tempnam(sys_get_temp_dir(), 'client-summary-native-');
        Logger::setLogFile($this->log);
        ClientReadFailureLog::reset();
    }

    /** Detaches owners before releasing their native base. */
    protected function tearDown(): void
    {
        $this->invoke('detachClientReadSummaryEventLoop');
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        ClientReadFailureLog::reset();
        Logger::resetLogFile();
        unlink($this->log);
        parent::tearDown();
    }

    private function bind(): void
    {
        $this->invoke('attachClientReadSummaryEventLoop');
    }

    /** @param string $method Private production seam exercised under the test guard */
    private function invoke(string $method): void
    {
        new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager);
    }

    /** @return EventLoopTimer The actual native timer */
    private function timer(): EventLoopTimer
    {
        return new ReflectionProperty(DaemonManager::class, 'clientReadSummaryTimer')->getValue($this->manager);
    }

    /**
     * @param string $server Independent failure key
     * @param float $origin Monotonic business basis before arming
     * @param int $count Warning and suppressed failures
     */
    private function flood(string $server, float $origin, int $count): void
    {
        for ($index = 0; $index < $count; ++$index) {
            ClientReadFailureLog::write($server, ClientReadFailureLog::READER_TICK, new InvalidJsonException('bad input'), $origin);
        }
    }

    /** @return string Journal written by guarded business code */
    private function logged(): string
    {
        return file_get_contents($this->log);
    }
}
