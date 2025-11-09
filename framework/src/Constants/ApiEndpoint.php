<?php

declare(strict_types=1);

namespace Hilos\Constants;

use BackedEnum;

/**
 * ApiEndpoint - API endpoint paths enumeration
 *
 * Defines all available API endpoint paths as an enumeration.
 * Centralized endpoint path management prevents typos and ensures consistency
 * across all HTTP clients and routers.
 *
 * @extends BackedEnum<string>
 */
enum ApiEndpoint: string
{
    /** @var string Daemon status endpoint */
    case STATUS = '/status';
}
