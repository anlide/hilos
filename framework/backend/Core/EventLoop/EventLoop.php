<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop;

use Event;
use EventBase;
use EventConfig;
use Hilos\Core\Daemon\ClientSocketDetacher;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\EventLoop\Exception\EventLoopPollException;
use Hilos\Core\EventLoop\Exception\NativeEventUnavailableException;
use Hilos\HilosException;
use Throwable;

/**
 * One native base and a bounded FIFO of process-local owner work. Native callbacks
 * only mark readiness; owner handlers run after the poll, inside the caller's guard.
 * Read handlers retain their existing containment contract: the origin logs failures.
 *
 * Unregister before closing a socket, through the master's {@see ClientSocketDetacher}
 * or {@see DaemonManager::closeBrowserEntrance()}. Owners close timer and ready handles
 * on detach; cleanup closes every remaining handle without closing sockets.
 *
 * The master waits through runOnce() only when its current owners have no runnable
 * work. tick() remains the nonblocking entry for other consumers.
 */
final class EventLoop
{
    /** Bounds native notifications before yielding to the ready queue and the manager. */
    private const int NATIVE_CALLBACK_BUDGET = 64;

    /** Bounds owner portions; continuations always wait for the next dispatch. */
    private const int READY_WORK_BUDGET = 64;

    private EventBase $base;

    /** @var array<string, array<int, Event>> Typed descriptor identity => READ/WRITE => native one-shot watch */
    private array $events = [];

    /** @var array<string, array<int, ReadyWork>> Typed descriptor identity => READ/WRITE => deferred handler */
    private array $ioWork = [];

    /** @var array<int, Event> POSIX signal watches keyed by signal number */
    private array $signals = [];

    /** @var array<int, ReadyWork> Deferred POSIX handlers keyed by signal number */
    private array $signalWork = [];

    /** @var array<int, EventLoopTimer> Lifetime references only, never scanned to find a deadline */
    private array $timers = [];

    /** @var array<int, ReadyWork> All open owner handles, keyed by object id */
    private array $work = [];

    /** @var array<int, ReadyWork> Insertion-ordered FIFO tickets, supporting constant-time cancellation */
    private array $ready = [];

    private int $nextTicket = 0;

    private int $nativeCallbacks = 0;

    private bool $nativeRemainder = false;

    private bool $dispatching = false;

    /**
     * Checks native capabilities before allocating the underlying libevent event base.
     *
     * @throws NativeEventUnavailableException When the required Hilos ext-event build is unavailable
     */
    public function __construct()
    {
        NativeEventRequirements::assertAvailable();
        $config = new EventConfig();
        // Match hrtime's precise monotonic source rather than libevent's coarse clock.
        $config->setFlags(EventBase::PRECISE_TIMER);
        $this->base = new EventBase($config);
    }

    /**
     * Native adapters share this base, keep callbacks to markNativeReady(), and release
     * their native objects before the loop is destroyed. They must not drive the base.
     *
     * @return EventBase The loop's existing native base, also used for TLS buffer events
     */
    public function getEventBase(): EventBase
    {
        return $this->base;
    }

    /**
     * @param callable(): void $callback Bounded owner portion; mark ready again only for runnable remainder
     * @return ReadyWork Owned handle; close it when the owner detaches
     */
    public function createReadyWork(callable $callback): ReadyWork
    {
        $work = new ReadyWork(
            $callback(...),
            function (ReadyWork $work): int {
                $ticket = ++$this->nextTicket;
                $this->ready[$ticket] = $work;
                return $ticket;
            },
            function (int $ticket): void {
                unset($this->ready[$ticket]);
            },
            function (ReadyWork $work): void {
                unset($this->work[spl_object_id($work)]);
            },
        );
        $this->work[spl_object_id($work)] = $work;
        return $work;
    }

    /**
     * @param callable(): void $callback Bounded owner portion after the deadline fires
     * @return EventLoopTimer Initially unarmed handle; close it when the owner detaches
     */
    public function createTimer(callable $callback): EventLoopTimer
    {
        $timer = new EventLoopTimer($this, $callback(...), function (EventLoopTimer $timer): void {
            unset($this->timers[spl_object_id($timer)]);
        });
        $this->timers[spl_object_id($timer)] = $timer;
        return $timer;
    }

    /**
     * Replaces only READ. A throwing read handler is contained as before; it logs at origin.
     * The handler receives the registered socket and READ flag, after native polling ends.
     *
     * @param resource|object $socket Socket resource or Socket object
     * @param callable $callback Read handler called as ($socket, int $flags)
     * @throws EventLoopException When the native READ watch cannot be installed
     */
    public function registerRead($socket, callable $callback): void
    {
        $this->registerIo($socket, Event::READ, function () use ($socket, $callback): void {
            try {
                $callback($socket, Event::READ);
            } catch (EventLoopException $failure) {
                throw $failure;
            } catch (Throwable) {
                // The originating read handler owns logging and failure containment.
            }
        });
    }

    /**
     * Replaces only WRITE. The owner must unregister WRITE when no bytes are runnable.
     * EAGAIN waits on the watch; it is not a reason to self-queue a ready continuation.
     *
     * @param resource|object $socket Socket resource or Socket object
     * @param callable $callback Write handler called as ($socket, int $flags)
     * @throws EventLoopException When the native WRITE watch cannot be installed
     */
    public function registerWrite($socket, callable $callback): void
    {
        $this->registerIo($socket, Event::WRITE, fn() => $callback($socket, Event::WRITE));
    }

    /**
     * @param resource|object $socket Socket whose READ watch and deferred handler are removed
     */
    public function unregisterRead($socket): void
    {
        $this->unregisterIo($this->socketKey($socket), Event::READ);
    }

    /**
     * @param resource|object $socket Socket whose WRITE watch and deferred handler are removed
     */
    public function unregisterWrite($socket): void
    {
        $this->unregisterIo($this->socketKey($socket), Event::WRITE);
    }

    /**
     * Frees both watches and queued callbacks before the caller closes its socket.
     * Repeated detach is harmless. Timer/ready handles owned by a client need their own close.
     *
     * @param resource|object $socket Socket resource or Socket object
     */
    public function unregister($socket): void
    {
        $this->unregisterRead($socket);
        $this->unregisterWrite($socket);
    }

    /**
     * Claims a POSIX signal for this base; do not install a pcntl handler for it concurrently.
     * Repeated native deliveries coalesce until the bounded handler portion runs.
     *
     * @param int $signal POSIX signal number
     * @param callable(int): void $callback Handler receiving the signal number
     * @throws EventLoopException When the native signal watch cannot be installed
     */
    public function registerSignal(int $signal, callable $callback): void
    {
        $this->unregisterSignal($signal);
        $work = $this->createReadyWork(fn() => $callback($signal));
        $event = null;
        try {
            $event = new Event(
                $this->base,
                $signal,
                Event::SIGNAL | Event::PERSIST,
                fn() => $this->markNativeReady($work),
            );
            // warning-suppressed: false becomes an EventLoopException in the caller's guard
            if (!@$event->add()) {
                throw new EventLoopException('The native signal watch could not be installed.');
            }
            $this->signals[$signal] = $event;
            $this->signalWork[$signal] = $work;
        } catch (Throwable $failure) {
            $work->close();
            $event?->free();
            throw new EventLoopException('The native signal watch could not be installed.', previous: $failure);
        }
    }

    /**
     * Releases native signal ownership and any queued delivery; safe to repeat.
     *
     * @param int $signal POSIX signal number
     */
    public function unregisterSignal(int $signal): void
    {
        if (!isset($this->signals[$signal])) {
            return;
        }

        $this->signalWork[$signal]->close();
        $this->signals[$signal]->del();
        $this->signals[$signal]->free();
        unset($this->signals[$signal], $this->signalWork[$signal]);
    }

    /** @return int Distinct watched sockets, including ones with both READ and WRITE */
    public function registeredCount(): int
    {
        return count($this->events);
    }

    /**
     * Timers waiting for their deadline and sockets waiting for I/O are not runnable.
     * A native batch stopped at its budget needs one nonblocking continuation poll.
     *
     * @return bool Whether a ready portion or possible native remainder needs another pass
     */
    public function hasReadyWork(): bool
    {
        return $this->ready !== [] || $this->nativeRemainder;
    }

    /**
     * Coalesces native readiness and ends a native batch at its callback budget.
     * Native adapters must call this once per notification, even if already ready.
     *
     * @param ReadyWork $work Handle created by this loop
     */
    public function markNativeReady(ReadyWork $work): void
    {
        $work->markReady();
        if (++$this->nativeCallbacks >= self::NATIVE_CALLBACK_BUDGET) {
            $this->nativeRemainder = true;
            $this->base->stop();
        }
    }

    /**
     * One bounded native batch followed by one bounded FIFO portion. Waits in libevent
     * only with no runnable work; there is no periodic timeout or artificial idle cap.
     * The caller encloses this call in its manager guard. Recursive dispatch is ignored.
     *
     * @throws HilosException Whatever a ready owner handler raises
     */
    public function runOnce(): void
    {
        $this->dispatch(false);
    }

    /**
     * Compatible nonblocking entry, with the same bounded portions as runOnce().
     *
     * @throws HilosException Whatever a ready owner handler raises
     */
    public function tick(): void
    {
        $this->dispatch(true);
    }

    /** Releases every native watch, timer and owner reference without closing sockets. */
    public function cleanup(): void
    {
        foreach (array_keys($this->events) as $socketKey) {
            $this->unregisterIo($socketKey, Event::READ);
            $this->unregisterIo($socketKey, Event::WRITE);
        }
        foreach (array_keys($this->signals) as $signal) {
            $this->unregisterSignal($signal);
        }
        foreach ($this->timers as $timer) {
            $timer->close();
        }
        foreach ($this->work as $work) {
            $work->close();
        }
        $this->nativeRemainder = false;
    }

    /** Alias for cleanup; repeatable and leaves the base available for reuse. */
    public function close(): void
    {
        $this->cleanup();
    }

    /** Releases all loop-owned handles on destruction. */
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * @param bool $nonblocking Whether the caller forbids waiting even when idle
     * @throws HilosException Whatever a ready owner handler raises
     */
    private function dispatch(bool $nonblocking): void
    {
        if ($this->dispatching) {
            return;
        }

        $this->dispatching = true;
        try {
            $flags = EventBase::LOOP_ONCE;
            if ($nonblocking || $this->hasReadyWork()) {
                $flags |= EventBase::LOOP_NONBLOCK;
            }
            $this->nativeCallbacks = 0;
            $this->nativeRemainder = false;
            try {
                // warning-suppressed: native failure is reported once by the enclosing iteration guard
                if (!@$this->base->loop($flags)) {
                    throw new EventLoopPollException('The native event base could not poll.');
                }
            } catch (Throwable $failure) {
                throw new EventLoopPollException('The native event base could not poll.', previous: $failure);
            }

            $lastTicket = $this->nextTicket;
            for ($remaining = self::READY_WORK_BUDGET; $remaining > 0; --$remaining) {
                // Removing the head advances this cursor; never rescan canceled tickets from the start.
                $ticket = key($this->ready);
                if ($ticket === null || $ticket > $lastTicket) {
                    break;
                }
                $this->ready[$ticket]->run();
            }
        } finally {
            $this->dispatching = false;
        }
    }

    /**
     * One-shot watches stay disabled until their deferred portion finishes. A level-triggered
     * readable socket therefore cannot keep native polling busy before ready dispatch begins.
     *
     * @param resource|object $socket Registered socket
     * @param int $direction READ or WRITE
     * @param callable(): void $callback One owner portion
     */
    private function registerIo($socket, int $direction, callable $callback): void
    {
        $socketKey = $this->socketKey($socket);
        $this->unregisterIo($socketKey, $direction);
        $event = null;
        $work = null;
        $work = $this->createReadyWork(function () use ($callback, $socketKey, $direction, &$event, &$work): void {
            try {
                $callback();
            } finally {
                if (!$work->isClosed()) {
                    try {
                        // warning-suppressed: false becomes an EventLoopException in the caller's guard
                        if (!@$event->add()) {
                            throw new EventLoopException('The native I/O watch could not be rearmed.');
                        }
                    } catch (Throwable $failure) {
                        $this->unregisterIo($socketKey, $direction);
                        throw new EventLoopException('The native I/O watch could not be rearmed.', previous: $failure);
                    }
                }
            }
        });
        try {
            $event = new Event($this->base, $socket, $direction, fn() => $this->markNativeReady($work));
            // warning-suppressed: false becomes an EventLoopException in the caller's guard
            if (!@$event->add()) {
                throw new EventLoopException('The native I/O watch could not be installed.');
            }
            $this->events[$socketKey][$direction] = $event;
            $this->ioWork[$socketKey][$direction] = $work;
        } catch (Throwable $failure) {
            $work->close();
            $event?->free();
            throw new EventLoopException('The native I/O watch could not be installed.', previous: $failure);
        }
    }

    /**
     * @param string $socketKey Typed descriptor identity
     * @param int $direction READ or WRITE
     */
    private function unregisterIo(string $socketKey, int $direction): void
    {
        if (!isset($this->events[$socketKey][$direction])) {
            return;
        }

        $this->ioWork[$socketKey][$direction]->close();
        $this->events[$socketKey][$direction]->del();
        $this->events[$socketKey][$direction]->free();
        unset($this->ioWork[$socketKey][$direction], $this->events[$socketKey][$direction]);
        if ($this->events[$socketKey] === []) {
            unset($this->ioWork[$socketKey], $this->events[$socketKey]);
        }
    }

    /**
     * @param resource|object $socket Socket resource or Socket object
     * @return string Identity whose resource, numeric-fd and object domains cannot collide
     */
    private function socketKey($socket): string
    {
        if (is_resource($socket)) {
            return 'resource:' . (int)$socket;
        }
        return is_int($socket) ? 'fd:' . $socket : 'object:' . spl_object_id($socket);
    }
}
