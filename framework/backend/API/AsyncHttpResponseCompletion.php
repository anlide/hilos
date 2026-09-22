<?php

declare(strict_types=1);

namespace Hilos\API;

/**
 * How far an in-flight HTTP response has gone, judged from the receive buffer.
 *
 * AsyncHttpClient::processReceiving() asks this after every read.
 */
enum AsyncHttpResponseCompletion: string
{
    /** @var string End reached: the body met its declared length, or the final zero-size chunk arrived */
    case COMPLETE = 'complete';

    /** @var string End was declared but not met; a close in this state is a cut-short body */
    case INCOMPLETE = 'incomplete';

    /** @var string No end declared yet, or headers are still unfinished; a close is a legal end */
    case UNTIL_CLOSE = 'until_close';

    /** @var string The declaration of the end is unreadable */
    case MALFORMED = 'malformed';
}
