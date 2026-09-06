<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DTO\TableAnchorDTO;

/**
 * Which side of the anchor a table window is taken from.
 *
 * The direction is what makes an anchor addressable: the same key values name both the rows
 * that follow them and the rows that precede them, and only this says which of the two the
 * window asked for. It is also what a null anchor points away from — {@see TableAnchorDTO}
 * absent means the edge of the set, the beginning with After and the end with Before.
 */
enum TableAnchorDirection: string
{
    /** Rows that follow the anchor in the window's own order. */
    case After = 'after';

    /** Rows that precede the anchor in the window's own order. */
    case Before = 'before';
}
