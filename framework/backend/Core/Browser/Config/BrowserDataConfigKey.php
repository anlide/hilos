<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Top-level keys for a declarative browser data source config.
 */
final class BrowserDataConfigKey
{
    public const string SOURCES = 'sources';
    public const string ROWS = 'rows';
    public const string PARAMS = 'params';
}
