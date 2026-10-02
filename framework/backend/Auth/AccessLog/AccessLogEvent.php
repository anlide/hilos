<?php

declare(strict_types=1);

namespace Hilos\Auth\AccessLog;

/**
 * The two uses of an account the access log records (HIL-1174).
 *
 * The values are the ones the `event` column of `hilos_access_log` declares, letter for letter,
 * and the ones a person's data export names.
 */
enum AccessLogEvent: string
{
    /** A session got the person, by any road of signing in. */
    case SIGN_IN = 'sign_in';

    /** A signed-in session connected from an address it did not have yet. */
    case NEW_ADDRESS = 'new_address';
}
