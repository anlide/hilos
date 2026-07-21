<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn\Exception;

/**
 * Thrown when a WebAuthn ceremony (attestation or assertion) fails to verify (HIL-284).
 *
 * Covers every ceremony-level rejection: a clientDataJSON that does not match the
 * expected type/challenge/origin, an authenticatorData whose RP-id hash or flags
 * are wrong, an unsupported/malformed credential public key, an invalid assertion
 * signature, or a signature-counter regression (cloned authenticator). The login
 * handler collapses this to a single generic `INVALID_CREDENTIALS` so it cannot be
 * used to probe which step failed; the register handler may surface more detail.
 */
class WebAuthnVerificationException extends WebAuthnException
{
}
