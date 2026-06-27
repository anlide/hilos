<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Top-level keys for a declarative browser page config.
 */
final class BrowserConfigKey
{
    public const string SIGNAL = 'signal';
    public const string PARAMS = 'params';
    public const string GUARDS = 'guards';
    public const string TABLES = 'tables';
}
