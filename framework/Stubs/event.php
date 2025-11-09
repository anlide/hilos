<?php

declare(strict_types=1);

/**
 * Minimal stub file for PHP event extension
 *
 * This is a STUB file. Methods have empty implementations and may return default values.
 * This file is for PhpStorm type hints only - DO NOT use in production code.
 *
 * Contains only Event, EventBase and EventConfig classes used in EventLoop.
 */

/**
 * Event class - represents an event firing on a file descriptor
 *
 * @see https://php.net/manual/en/class.event.php
 */
final class Event
{
    public const int ET = 32;
    public const int PERSIST = 16;
    public const int READ = 2;
    public const int WRITE = 4;
    public const int SIGNAL = 8;
    public const int TIMEOUT = 1;

    /**
     * Constructs Event object
     *
     * @param EventBase $base Event base instance
     * @param mixed $fd File descriptor (socket resource)
     * @param int $what Event flags (Event::READ, Event::WRITE, etc.)
     * @param callable $cb Callback function
     * @param mixed $arg Optional callback argument
     */
    public function __construct(EventBase $base, mixed $fd, int $what, callable $cb, mixed $arg = null) {}

    /**
     * Makes event pending
     *
     * @param float $timeout Optional timeout
     * @return bool
     */
    public function add(float $timeout = -1): bool
    {
        return false; // Stub implementation
    }

    /**
     * Makes event non-pending
     *
     * @return bool
     */
    public function del(): bool
    {
        return false; // Stub implementation
    }

    /**
     * Make event non-pending and free resources allocated for this event
     */
    public function free(): void
    {
        // Stub implementation
    }
}

/**
 * EventBase class - represents libevent's event base structure
 *
 * @see https://php.net/manual/en/class.eventbase.php
 */
final class EventBase
{
    public const int LOOP_ONCE = 1;
    public const int LOOP_NONBLOCK = 2;
    public const int NOLOCK = 1;
    public const int STARTUP_IOCP = 4;
    public const int NO_CACHE_TIME = 8;
    public const int EPOLL_USE_CHANGELIST = 16;
    public const int IGNORE_ENV = 2;
    public const int PRECISE_TIMER = 32;

    /**
     * Constructs EventBase object
     *
     * @param null|EventConfig $cfg Optional event configuration
     */
    public function __construct(?EventConfig $cfg = null) {}

    /**
     * Dispatch pending events
     *
     * @param int $flags EventBase::LOOP_ONCE, EventBase::LOOP_NONBLOCK, etc.
     */
    public function loop(int $flags = 0): void
    {
        // Stub implementation
    }

    /**
     * Stop dispatching events
     *
     * @param float $timeout Optional timeout
     * @return bool
     */
    public function stop(float $timeout = 0.0): bool
    {
        return false; // Stub implementation
    }
}

/**
 * EventConfig class - used for event base configuration
 *
 * @see https://php.net/manual/en/class.eventconfig.php
 */
final class EventConfig
{
    /**
     * Constructs EventConfig object
     */
    public function __construct() {}
}
