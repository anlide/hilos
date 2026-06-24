<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Guard types supported by browser page configs.
 */
final class BrowserGuardType
{
    public const string DB_EXISTS = 'db_exists';

    /** Access guard: the connection's current user must hold a truthy flag field (e.g. admin). */
    public const string ACCESS = 'access';
}
