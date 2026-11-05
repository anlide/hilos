<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Event;
use EventBase;
use Hilos\Core\Exception\ProcessException;
use Throwable;

/**
 * Process-local wait for the container watchdog. Callbacks only remember signals;
 * the watchdog applies them outside libevent and observes its child through Process.
 */
final class WatchdogWait
{
    /** @var list<int> Signals owned exclusively by this event base */
    private const array SIGNALS = [SIGCHLD, SIGTERM, SIGINT, SIGHUP];

    /** @var ?EventBase Watchdog's event base, absent after close */
    private ?EventBase $base = null;

    /** @var list<Event> Persistent signal watches, including any partial installation */
    private array $signalEvents = [];

    /** @var ?Event One-shot bound for the current wait */
    private ?Event $timer = null;

    /** @var array<int, true> Signals delivered since the last consumption */
    private array $pendingSignals = [];

    /**
     * Installs the single receiver before any child is started. The original mask
     * is restored even when installation fails, so a child never inherits our block.
     *
     * @throws ProcessException If the mask or a signal watch cannot be installed or restored
     */
    public function __construct()
    {
        if (!pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS, $originalMask)) {
            throw new ProcessException('Cannot block signals while installing the watchdog wait.');
        }

        try {
            $this->base = new EventBase();
            foreach (self::SIGNALS as $signal) {
                $event = Event::signal($this->base, $signal, function (int $receivedSignal): void {
                    $this->pendingSignals[$receivedSignal] = true;
                });
                if ($event === false) {
                    throw new ProcessException("Cannot create watchdog signal event for signal $signal.");
                }
                $this->signalEvents[] = $event;
                if (!$event->add()) {
                    throw new ProcessException("Cannot install watchdog signal event for signal $signal.");
                }
            }
            $this->timer = new Event($this->base, -1, Event::TIMEOUT, static function (): void {
            });
        } catch (Throwable $failure) {
            $this->close();
            throw $failure;
        } finally {
            if (!pcntl_sigprocmask(SIG_SETMASK, $originalMask)) {
                $this->close();
                throw new ProcessException('Cannot restore the signal mask after installing the watchdog wait.');
            }
        }
    }

    /**
     * Dispatches already delivered events without waiting, then clears their marks.
     *
     * @return list<int> Signals to apply in ordinary watchdog code
     * @throws ProcessException If the wait is closed or pending events cannot be dispatched
     */
    public function consumeSignals(): array
    {
        $this->dispatchPending();
        $signals = array_keys($this->pendingSignals);
        $this->pendingSignals = [];
        return $signals;
    }

    /**
     * Returns immediately for a delivered signal or an already due deadline.
     * Signals stay marked until consumeSignals(), including those delivered here.
     *
     * @param float $timeoutSeconds Maximum wait in seconds, zero when work is already due
     * @throws ProcessException If the timer cannot be armed or the event loop fails
     */
    public function wait(float $timeoutSeconds): void
    {
        $this->dispatchPending();
        if ($this->pendingSignals !== [] || $timeoutSeconds <= 0) {
            return;
        }

        try {
            if ($this->timer === null || !$this->timer->add($timeoutSeconds)) {
                throw new ProcessException('Cannot arm the watchdog wait timer.');
            }
            if (!$this->base->loop(EventBase::LOOP_ONCE)) {
                throw new ProcessException('Cannot wait for watchdog events.');
            }
        } finally {
            $this->timer?->del();
        }
    }

    /** Releases the timer, signal watches and base, including partial installation. */
    public function close(): void
    {
        $this->timer?->del();
        $this->timer?->free();
        $this->timer = null;
        foreach ($this->signalEvents as $event) {
            $event->del();
            $event->free();
        }
        $this->signalEvents = [];
        $this->base?->free();
        $this->base = null;
    }

    /** Releases watches if their owner leaves without an explicit close. */
    public function __destruct()
    {
        $this->close();
    }

    /** @throws ProcessException If the wait is closed or pending events cannot be dispatched */
    private function dispatchPending(): void
    {
        if ($this->base === null || !$this->base->loop(EventBase::LOOP_NONBLOCK)) {
            throw new ProcessException('Cannot dispatch pending watchdog events.');
        }
    }
}
