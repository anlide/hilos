<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\Exception;

use Hilos\HilosException;

/**
 * Thrown when a login rotation could not mint a session token nobody holds (HIL-582).
 *
 * A 128-bit token makes this a theoretical outcome rather than an expected one, so it
 * is an exception and not a fallback: the honest alternative would be to let the login
 * proceed on the token it was given, and that token is the one the rotation exists to
 * abandon. A login that cannot rotate does not happen.
 *
 * A caller seeing this repeatedly is not looking at bad luck - it is a random source
 * returning the same bytes, or a session table that is not what the seam thinks it is.
 */
class SessionTokenExhaustedException extends HilosException
{
}
