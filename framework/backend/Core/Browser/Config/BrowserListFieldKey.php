<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Item source keys inside a declarative browser list config.
 */
final class BrowserListFieldKey
{
    public const string SOURCE = 'source';
    public const string ITEM_KEY = 'itemKey';
    public const string FIELDS = 'fields';
    public const string COMPUTED = 'computed';
    public const string TRIGGERS = 'triggers';
    public const string WHERE = 'where';
    public const string VIA = 'via';
    public const string MANY = 'many';
}
