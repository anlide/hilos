<?php

declare(strict_types=1);

namespace Hilos\API;

/**
 * AsyncHttpState - Async HTTP request state enumeration
 *
 * Represents possible states of async HTTP request in AsyncHttpClient.
 *
 * State flow: CONNECTING → SENDING → RECEIVING → DONE
 */
enum AsyncHttpState: string
{
    case CONNECTING = 'connecting';
    case SENDING = 'sending';
    case RECEIVING = 'receiving';
    case DONE = 'done';
}
