<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\Exception;

/**
 * Thrown when an action references an OAuth provider key that is not configured
 * in the registry (HIL-281).
 *
 * Raised synchronously in the start/callback handlers (no I/O), so it surfaces
 * as an immediate `action_error`.
 */
class OAuthUnknownProviderException extends OAuthException
{
}
