<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Exception;

/**
 * Thrown when a provider's token or userinfo response cannot be used (HIL-281).
 *
 * Covers a malformed or non-JSON body, a missing access token, or a userinfo
 * payload with no resolvable subject. Post-exchange failures reach the client as
 * the generic OAuth failure signal, not as a distinguishing message.
 */
class OAuthProviderException extends OAuthException
{
}
