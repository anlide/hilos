<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Browser subscription error markers used by declarative guards.
 */
final class BrowserSubscriptionError
{
    public const int NOT_FOUND = 404;
    public const int FORBIDDEN = 403;
}
