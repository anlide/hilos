<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop;

use EventBase;
use Event;
use Hilos\Core\Daemon\ClientSocketDetacher;
use Throwable;

/**
 * Thin wrapper around the ext-event (libevent) EventBase owned by the daemon's
 * main loop. It watches sockets for readability, drains all ready events once
 * per non-blocking tick, and frees the underlying events on close.
 *
 * Read callbacks are wrapped so a throwing handler is swallowed instead of
 * aborting the loop; the originating handler logs the failure. A socket must
 * come off the watch before it is closed, otherwise libevent keeps a dangling
 * reference to a closed descriptor - but that order is nobody's to repeat here:
 * the only caller of unregister() is the master's detach seam
 * ({@see ClientSocketDetacher}), which every exit path goes through.
 */
final class EventLoop
{
    private EventBase $base;

    /** @var array<int, Event> Registered read events keyed by socket id */
    private array $events = [];

    /**
     * Allocates the underlying libevent event base.
     */
    public function __construct()
    {
        $this->base = new EventBase();
    }

    /**
     * Watches a socket for readability, replacing any existing registration for it.
     *
     * @param resource|object $socket Socket resource or Socket object
     * @param callable $callback Read handler called as ($socket, int $flags)
     */
    public function registerRead($socket, callable $callback): void
    {
        $socketKey = $this->socketKey($socket);
        if (isset($this->events[$socketKey])) {
            $this->unregister($socket);
        }

        // Swallow handler exceptions so one bad read cannot abort the loop;
        // the originating handler is responsible for logging the failure.
        $event = new Event(
            $this->base,
            $socket,
            Event::READ | Event::PERSIST,
            function ($socket, $flags) use ($callback) {
                try {
                    return $callback($socket, $flags);
                } catch (Throwable) {
                    return false;
                }
            },
        );

        /** @noinspection PhpExpressionResultUnusedInspection */
        $event->add();
        $this->events[$socketKey] = $event;
    }

    /**
     * Removes and frees the read event for a socket; a no-op when none is registered.
     *
     * Must run before the socket is closed so libevent drops its reference first.
     *
     * @param resource|object $socket Socket resource or Socket object
     */
    public function unregister($socket): void
    {
        $socketKey = $this->socketKey($socket);
        if (!isset($this->events[$socketKey])) {
            return;
        }

        /** @noinspection PhpExpressionResultUnusedInspection */
        $this->events[$socketKey]->del();
        /** @noinspection PhpExpressionResultUnusedInspection */
        $this->events[$socketKey]->free();
        unset($this->events[$socketKey]);
    }

    /**
     * Counts the sockets currently on the watch.
     *
     * Exists to be measured: a registration a departing client leaves behind is
     * invisible from the outside, and this is what a test reads to tell a client that
     * left from one that only looks gone.
     *
     * @return int Number of registered read events
     */
    public function registeredCount(): int
    {
        return count($this->events);
    }

    /**
     * Processes every ready event once and returns immediately (non-blocking).
     */
    public function tick(): void
    {
        /** @noinspection PhpExpressionResultUnusedInspection */
        $this->base->loop(EventBase::LOOP_NONBLOCK);
    }

    /**
     * Removes and frees every registered event.
     */
    public function cleanup(): void
    {
        foreach ($this->events as $event) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $event->del();
            /** @noinspection PhpExpressionResultUnusedInspection */
            $event->free();
        }
        $this->events = [];
    }

    /**
     * Frees every registered event when the loop is destroyed.
     */
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Computes the stable integer key under which a socket's event is stored.
     *
     * @param resource|object $socket Socket resource or Socket object
     * @return int Resource id for a resource/int, otherwise the spl object id
     */
    private function socketKey($socket): int
    {
        return is_resource($socket) || is_int($socket) ? (int)$socket : spl_object_id($socket);
    }
}
