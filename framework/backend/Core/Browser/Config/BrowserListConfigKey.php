<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Top-level keys for a declarative browser list source config.
 */
final class BrowserListConfigKey
{
    public const string SOURCES = 'sources';
    public const string ITEMS = 'items';
    public const string PARAMS = 'params';
}
