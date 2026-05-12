<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Top-level keys for declarative browser page and table configs.
 */
final class BrowserConfigKey
{
    public const string SIGNAL = 'signal';
    public const string PARAMS = 'params';
    public const string GUARDS = 'guards';
    public const string TABLES = 'tables';
    public const string SOURCES = 'sources';
    public const string ROWS = 'rows';
}
