<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Table\TableSearchTerm;

/**
 * Where the `?` placeholders of a statement stand: the ones a parameter is substituted into, and not
 * the question marks a string literal of the text happens to contain.
 *
 * The database layer binds parameters itself, so the boundaries of a string literal have to be read
 * from the statement text before anything is substituted into it. Four rules make that reading: a
 * quote outside a literal opens one; inside a literal a backslash escapes exactly one character, so a
 * pair of them consumes itself and does not escape a quote standing behind it; a doubled quote of the
 * literal's own kind is a literal quote and keeps the literal open; a quote of the other kind is an
 * ordinary character.
 *
 * A backslash always escapes here, and `NO_BACKSLASH_ESCAPES` is deliberately not consulted: the
 * mode a server happens to run with is not read at all. That is no oversight but the decision of
 * the leaf - reading the mode would make the reading of a text depend on a connection and on a
 * server's configuration, while the default of MySQL and MariaDB is the one assumed here. The same
 * mode is the reason a table's search pattern names its own escape character -
 * {@see TableSearchTerm::LIKE_COMPARISON} says the rest of that story.
 *
 * Not a validator of SQL: nothing here is thrown. A text that ends inside an unclosed literal simply
 * keeps the question marks of that literal out of the list, and the server answers for the syntax.
 * SQL comments are not understood either, so a question mark inside one is counted as a placeholder;
 * no statement of the framework or of a demo carries a comment.
 */
final class SqlPlaceholders
{
    /** Opens a string literal, and closes the one it opened. */
    private const string SINGLE_QUOTE = "'";

    /** The other quote a string literal can be written with. */
    private const string DOUBLE_QUOTE = '"';

    /** Inside a literal, makes the next character stand for itself. */
    private const string ESCAPE_PREFIX = '\\';

    /** Stands for a bound parameter wherever it is not inside a literal. */
    private const string PLACEHOLDER = '?';

    /**
     * @param string $sql Statement text, parameters not yet substituted
     * @return list<int> Byte offsets of the `?` characters that stand outside a string literal
     */
    public static function positions(string $sql): array
    {
        $positions = [];
        $length = strlen($sql);
        $openedBy = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($openedBy === null) {
                if ($char === self::SINGLE_QUOTE || $char === self::DOUBLE_QUOTE) {
                    $openedBy = $char;
                } elseif ($char === self::PLACEHOLDER) {
                    $positions[] = $i;
                }
                continue;
            }

            if ($char === self::ESCAPE_PREFIX) {
                $i++;
                continue;
            }

            if ($char !== $openedBy) {
                continue;
            }

            if ($i + 1 < $length && $sql[$i + 1] === $openedBy) {
                $i++;
                continue;
            }

            $openedBy = null;
        }

        return $positions;
    }
}
