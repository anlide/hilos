<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Exception;

/**
 * Thrown when an OAuth callback `state` fails verification (HIL-281).
 *
 * The CSRF guard of the flow: a state that is malformed, has a bad signature,
 * has expired, or does not match the initiating session is rejected here. The
 * callback handler surfaces this as a generic `action_error` so the failure
 * reason never leaks to the client.
 */
class OAuthStateException extends OAuthException
{
}
