<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop;

use Closure;
use Hilos\HilosException;
use Throwable;

/** One owner's coalesced, cancelable portion of process-local work. */
final class ReadyWork
{
    private ?int $ticket = null;

    /**
     * Constructed by EventLoop::createReadyWork(); owners keep the returned handle.
     * A handler does one bounded portion and marks itself ready again only for runnable work.
     *
     * @param ?Closure(): void $callback Owner's portion, released on close
     * @param ?Closure(ReadyWork): int $enqueue Append to the loop's FIFO
     * @param ?Closure(int): void $remove Remove a pending FIFO ticket
     * @param ?Closure(ReadyWork): void $release Release the loop's lifetime reference
     * @internal Loop binding, not an owner constructor
     */
    public function __construct(
        private ?Closure $callback,
        private ?Closure $enqueue,
        private ?Closure $remove,
        private ?Closure $release,
    ) {
    }

    /** Coalesces repeated notifications; a closed handle ignores late notifications. */
    public function markReady(): void
    {
        if ($this->callback !== null && $this->ticket === null) {
            $this->ticket = ($this->enqueue)($this);
        }
    }

    /** Removes queued work immediately, leaving the handle reusable. */
    public function cancel(): void
    {
        if ($this->ticket !== null) {
            ($this->remove)($this->ticket);
            $this->ticket = null;
        }
    }

    /** Permanently detaches the owner and its pending portion; safe to repeat. */
    public function close(): void
    {
        if ($this->callback === null) {
            return;
        }

        $this->cancel();
        $this->callback = null;
        ($this->release)($this);
        $this->enqueue = null;
        $this->remove = null;
        $this->release = null;
    }

    /** @return bool Whether the owner has detached permanently */
    public function isClosed(): bool
    {
        return $this->callback === null;
    }

    /**
     * Consumes readiness before calling the owner so a continuation joins the FIFO tail.
     * Failures retire this handle and reach the manager guard; other queued owners stay intact.
     *
     * @throws HilosException Whatever the owner's portion raises
     * @internal Called only by the loop's ready dispatcher
     */
    public function run(): void
    {
        if ($this->ticket === null) {
            return;
        }

        $this->cancel();
        try {
            ($this->callback)();
        } catch (Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }
}
