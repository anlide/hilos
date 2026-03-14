<?php

declare(strict_types=1);

namespace Hilos\API;

/**
 * AsyncHttpState - Async HTTP request state enumeration.
 *
 * Represents possible states of async HTTP request in AsyncHttpClient.
 * State flow: CONNECTING → SENDING → RECEIVING → DONE
 */
enum AsyncHttpState: string
{
    /** @var string Socket connection in progress */
    case CONNECTING = 'connecting';

    /** @var string Sending request body */
    case SENDING = 'sending';

    /** @var string Receiving response */
    case RECEIVING = 'receiving';

    /** @var string Request completed */
    case DONE = 'done';
}
