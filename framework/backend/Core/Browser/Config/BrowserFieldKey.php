<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Shared row/item field keys the browser engine reads from any source's
 * declarative row config, regardless of source kind. A source declares its
 * config through the per-kind list/table/data dictionaries; these are the
 * common field keys those dictionaries share and the engine resolves.
 */
final class BrowserFieldKey
{
    public const string SOURCE = 'source';
    public const string FIELDS = 'fields';
    public const string COMPUTED = 'computed';
    public const string TRIGGERS = 'triggers';
    public const string WHERE = 'where';
    public const string VIA = 'via';
    public const string MANY = 'many';
}
