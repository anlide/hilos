<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * Row source keys inside a declarative browser table config.
 */
final class BrowserTableFieldKey
{
    public const string SOURCE = 'source';
    public const string ROW_KEY = 'rowKey';
    public const string FIELDS = 'fields';
    public const string COMPUTED = 'computed';
    public const string TRIGGERS = 'triggers';
    public const string WHERE = 'where';
    public const string VIA = 'via';
    public const string MANY = 'many';

    /**
     * Wire names of the row's fields that came from an RT source or are computed, shown to a viewer of the admin
     * view mode as they are (HIL-1250). A field copied out of a database column is never named here: whether it
     * is personal is its column's verdict.
     */
    public const string NOT_PERSONAL = 'notPersonal';
}
