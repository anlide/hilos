<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn\Exception;

/**
 * Thrown when a signed WebAuthn challenge token fails verification (HIL-284).
 *
 * The replay/CSRF guard of both ceremonies: a challenge token that is malformed,
 * has a bad signature, was minted for a different purpose or session, or has
 * expired is rejected here. The confirm handler surfaces this as a generic
 * error so the reason never leaks to the client.
 */
class WebAuthnChallengeException extends WebAuthnException
{
}
