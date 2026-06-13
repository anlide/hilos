<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Hilos\Constants\HilosHttpHeaders;

/**
 * HTTP Headers constants.
 *
 * Project-specific HTTP header names.
 */
final class HttpHeaders
{
    /** @var string Session token header name (WebSocket authentication) */
    public const string SESSION_TOKEN = HilosHttpHeaders::HILOS_SESSION_TOKEN;

    /** @var string nginx internal-redirect header that hands file streaming to the web server */
    public const string X_ACCEL_REDIRECT = 'X-Accel-Redirect';
}
