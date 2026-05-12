<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Row source keys inside declarative browser table configs.
 */
final class BrowserFieldKey
{
    public const string SOURCE = 'source';
    public const string ROW_KEY = 'rowKey';
    public const string FIELDS = 'fields';
    public const string COMPUTED = 'computed';
    public const string WHERE = 'where';
    public const string VIA = 'via';
    public const string MANY = 'many';
}
