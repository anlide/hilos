<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * TableWindowRefusalCode - machine-readable reasons a table window is refused.
 *
 * The client reads the code, not a message: the view draws one phrase for every
 * code. Two reasons, and there is no third - a window is refused because nobody
 * serves that table, or because the server could not build it.
 */
final class TableWindowRefusalCode
{
    /** No server table answers this key. */
    public const string NOT_SERVED = 'table_not_served';

    /**
     * Not a kind of refusal anyone decided but a crash, told apart from the one
     * above by carrying no `table_` prefix: the window failed on something nobody
     * decided, the way its group twin reports the same accident.
     */
    public const string INTERNAL_ERROR = 'internal_error';
}
