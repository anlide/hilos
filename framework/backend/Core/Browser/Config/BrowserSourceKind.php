<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Config;

/**
 * The kind of a browser source, deciding which page_response payload section it
 * feeds. A `table` source is the heavy row collection (viewport, pending/Apply
 * downstream); a `list` source is a lighter ordered item collection (chat log,
 * menus); a `data` source is single-row page-data collapsed into one blob.
 * Entity fragments are extracted from item/row slots by the frontend normalizer
 * regardless of kind, so there is no separate entity kind here.
 *
 * A source declares its kind by the NAME of its source-key constant: `LIST`,
 * `DATA`, or `TABLE` (the uppercased kind), holding the source key as its value.
 * There is no separate kind flag; the constant name carries the kind.
 */
final class BrowserSourceKind
{
    public const string TABLE = 'table';
    public const string LIST = 'list';
    public const string DATA = 'data';
}
