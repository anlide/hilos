<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/**
 * HttpState - HTTP request state enumeration
 *
 * Represents possible states of async HTTP request in monitor.
 */
enum HttpState: string
{
    case IDLE = 'idle';
    case CONNECTING = 'connecting';
    case SENDING = 'sending';
    case RECEIVING = 'receiving';
}

