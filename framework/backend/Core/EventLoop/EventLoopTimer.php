<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop;

use Closure;
use Event;
use Hilos\Constants\TimeConstants;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Throwable;

/** A one-shot native timer; its deadline is absolute monotonic seconds, never epoch time. */
final class EventLoopTimer
{
    private ?Event $event = null;

    private ReadyWork $ready;

    private ?float $deadline = null;

    private int $generation = 0;

    private ?int $dueGeneration = null;

    /**
     * @param EventLoop $loop Native base and ready queue that own this timer
     * @param ?Closure(): void $callback Owner's bounded portion, released on close
     * @param ?Closure(EventLoopTimer): void $release Remove the loop's lifetime reference
     * @internal Use EventLoop::createTimer() for lifetime registration
     */
    public function __construct(
        private EventLoop $loop,
        private ?Closure $callback,
        private ?Closure $release,
    ) {
        $this->ready = $loop->createReadyWork(function (): void {
            if ($this->dueGeneration !== $this->generation || $this->callback === null) {
                return;
            }

            $this->dueGeneration = null;
            ($this->callback)();
        });
    }

    /**
     * Replaces the deadline only when it changes; null cancels native and queued work.
     * Cancellation invalidates even a previously activated native callback. Firing clears
     * the deadline before the owner runs; another firing requires an explicit arm.
     *
     * @param ?float $monotonicAt Absolute hrtime(true) seconds, or null to cancel
     * @throws EventLoopException When the deadline is not finite, the timer is closed, or native arming fails
     */
    public function armAt(?float $monotonicAt): void
    {
        if ($monotonicAt === null) {
            $this->cancel();
            return;
        }
        if ($this->callback === null || !is_finite($monotonicAt)) {
            throw new EventLoopException('A timer needs a finite deadline and an open handle.');
        }
        if ($monotonicAt === $this->deadline) {
            return;
        }

        $this->cancel();
        $generation = $this->generation;
        $this->event = Event::timer($this->loop->getEventBase(), function () use ($generation): void {
            if ($generation !== $this->generation || $this->callback === null) {
                return;
            }

            $this->deadline = null;
            $this->dueGeneration = $generation;
            $this->loop->markNativeReady($this->ready);
        });
        $this->deadline = $monotonicAt;
        try {
            // warning-suppressed: false becomes an EventLoopException without invoking a PHP lifecycle hook
            if (!@$this->event->add(max(
                0.0,
                $monotonicAt - hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND,
            ))) {
                throw new EventLoopException('The native timer could not be armed.');
            }
        } catch (Throwable $failure) {
            $this->cancel();
            throw new EventLoopException('The native timer could not be armed.', previous: $failure);
        }
    }

    /** @return ?float Armed monotonic deadline, or null after cancellation or native firing */
    public function getDeadline(): ?float
    {
        return $this->deadline;
    }

    /** Releases native and queued work and the owner; safe to repeat. */
    public function close(): void
    {
        if ($this->callback === null) {
            return;
        }

        $this->cancel();
        $this->ready->close();
        $this->callback = null;
        ($this->release)($this);
        $this->release = null;
    }

    private function cancel(): void
    {
        ++$this->generation;
        $this->deadline = null;
        $this->dueGeneration = null;
        $this->ready->cancel();
        $this->event?->del();
        $this->event?->free();
        $this->event = null;
    }
}
