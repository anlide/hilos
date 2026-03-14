<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop;

use EventBase;
use Event;

/**
 * EventLoop - Manages epoll-based event loop.
 *
 * Wrapper around PHP event extension for managing socket events.
 * Works with DaemonManager main loop.
 */
class EventLoop
{
    /** @var EventBase Event base instance */
    private EventBase $base;

    /** @var array<int|string, Event> Registered events indexed by socket resource ID or "id_write" */
    private array $events = [];

    /** @var int Counter for active events (for debugging) */
    private int $activeEvents = 0;

    /**
     * Creates event loop instance.
     */
    public function __construct()
    {
        $this->base = new EventBase();
    }

    /**
     * Register socket for read events
     *
     * @param resource|object $socket Socket resource or Socket object
     * @param callable $callback Callback function(resource $socket, int $flags)
     */
    public function registerRead($socket, callable $callback): void
    {
        $socketId = is_resource($socket) || is_int($socket) ? (int)$socket : spl_object_id($socket);

        // Remove existing event if any
        if (isset($this->events[$socketId])) {
            $this->unregister($socket);
        }

        // Wrap callback to catch exceptions (logging is handled in onClientRead/onServerAccept)
        $wrappedCallback = function($socket, $flags) use ($callback) {
            try {
                return $callback($socket, $flags);
            } catch (\Throwable $e) {
                // Don't rethrow - allow event loop to continue
                // Exception will be logged in onClientRead/onServerAccept handlers
                return false;
            }
        };

        $event = new Event(
            $this->base,
            $socket,
            Event::READ | Event::PERSIST,
            $wrappedCallback,
        );

        /** @noinspection PhpExpressionResultUnusedInspection */
        $event->add();
        $this->events[$socketId] = $event;
        $this->activeEvents++;
    }

    /**
     * Register socket for write events
     *
     * @param resource|object $socket Socket resource or Socket object
     * @param callable $callback Callback function(resource $socket, int $flags)
     */
    public function registerWrite($socket, callable $callback): void
    {
        $socketId = is_resource($socket) || is_int($socket) ? (int)$socket : spl_object_id($socket);

        // Wrap callback to catch exceptions (logging is handled in handler methods)
        $wrappedCallback = function($socket, $flags) use ($callback) {
            try {
                return $callback($socket, $flags);
            } catch (\Throwable $e) {
                // Don't rethrow - allow event loop to continue
                // Exception will be logged in handler methods
                return false;
            }
        };

        $event = new Event(
            $this->base,
            $socket,
            Event::WRITE | Event::PERSIST,
            $wrappedCallback,
        );

        /** @noinspection PhpExpressionResultUnusedInspection */
        $event->add();
        $this->events[$socketId . '_write'] = $event;
        $this->activeEvents++;
    }

    /**
     * Unregister socket events
     *
     * @param resource|object $socket Socket resource or Socket object
     */
    public function unregister($socket): void
    {
        $socketId = is_resource($socket) || is_int($socket) ? (int)$socket : spl_object_id($socket);

        // Remove read event
        if (isset($this->events[$socketId])) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->events[$socketId]->del();
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->events[$socketId]->free();
            unset($this->events[$socketId]);
            $this->activeEvents--;
        }

        // Remove write event if exists
        if (isset($this->events[$socketId . '_write'])) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->events[$socketId . '_write']->del();
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->events[$socketId . '_write']->free();
            unset($this->events[$socketId . '_write']);
            $this->activeEvents--;
        }
    }

    /**
     * Run event loop iteration (non-blocking)
     *
     * Processes all ready events and returns immediately.
     */
    public function tick(): void
    {
        // Non-blocking loop - process ready events and return
        // Exceptions in callbacks are handled by wrapper functions
        /** @noinspection PhpExpressionResultUnusedInspection */
        $this->base->loop(EventBase::LOOP_NONBLOCK);
    }

    /**
     * Get number of active events
     *
     * @return int Active events count
     */
    public function getActiveEventsCount(): int
    {
        return $this->activeEvents;
    }

    /**
     * Stops event loop.
     */
    public function stop(): void
    {
        /** @noinspection PhpExpressionResultUnusedInspection */
        $this->base->stop();
    }

    /**
     * Frees all registered events.
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
        $this->activeEvents = 0;
    }

    /**
     * Destructor. Cleans up all events.
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
