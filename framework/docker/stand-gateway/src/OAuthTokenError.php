<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * OAuthTokenError - why the emulator would not exchange a code for a token (HIL-923).
 *
 * Four refusals and no wording: what a provider SAYS for each of them is the provider's own
 * business and lives in {@see OAuthProfile::tokenRefusal()}, down to whether the refusal is a
 * status at all - GitHub answers 200 and writes the refusal where the token would have been.
 *
 * The order the exchange checks them in is the order below, and it is deliberate: the likeliest
 * mistake a spec makes is the code, and a complaint about the client on top of a spoilt code would
 * send the reader looking in the wrong place.
 */
enum OAuthTokenError
{
    /** The grant type is not `authorization_code`. */
    case UNSUPPORTED_GRANT;

    /** The code is unknown, already spent, or past its life. */
    case BAD_CODE;

    /** The client id is not the one the code was issued to, or the client secret is empty. */
    case BAD_CLIENT;

    /** The callback address is not the one the consent screen carried. */
    case REDIRECT_MISMATCH;
}
