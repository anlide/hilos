<?php

declare(strict_types=1);

namespace Hilos\API;

/**
 * AsyncCommandState - async command-channel request state.
 *
 * Represents the possible states of an async command request in
 * {@see AsyncCommandClient}. State flow: CONNECTING → SENDING → RECEIVING → DONE.
 */
enum AsyncCommandState: string
{
    /** @var string Socket connection in progress */
    case CONNECTING = 'connecting';

    /** @var string Sending the request line */
    case SENDING = 'sending';

    /** @var string Receiving the reply line */
    case RECEIVING = 'receiving';

    /** @var string Request completed */
    case DONE = 'done';
}
