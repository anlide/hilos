<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * How a search term is compared with the value of one searched field.
 *
 * The table declares it on the server, per field, and it never reaches the wire: the window carries
 * the term as the reader typed it, and what the term means is the table's to say. A field declared
 * by its bare column is searched the first way, which is how every field was searched before the
 * second way existed.
 */
enum TableSearchMatch
{
    /** The term is a piece of the value, found anywhere in it, and every character of it stands for itself. */
    case Substring;

    /**
     * A term carrying a star is matched against the whole value, the star standing for any run of
     * characters, the empty one too, and every other character for itself; a term with no star is
     * searched as a substring.
     */
    case Mask;
}
