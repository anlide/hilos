<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Closure;

/**
 * The one reading of a window's search term: what counts as a term, and what it matches.
 *
 * Three paths search a table - the ORM's page query, a table's own SQL and the in-memory filter -
 * and each of them used to answer both questions for itself. They disagreed on the same input: the
 * delivery log trimmed the edges of the term and the ORM did not, and a percent sign somebody typed
 * meant "anything at all" in the database and a percent sign in memory. One reading here is what
 * makes the answer the same wherever the rows are read from.
 *
 * What the term matches also depends on the field it is compared with, and that is the table's to
 * say ({@see TableSearchMatch}). In a field declared as a mask a star the reader typed stands for any
 * run of characters and the term is matched against the whole value, so `*-raw.log` finds the names
 * that end so; in every other field the star is one more character that stands for itself. Both
 * the pattern the database is given and the comparison run in memory are built here, which is what
 * keeps the star meaning one thing on every path.
 */
final class TableSearchTerm
{
    /**
     * How a searched column is compared with the pattern built here, escape character and all.
     *
     * The escape character is named rather than left to the server: with the clause omitted a
     * pattern is read under whatever `NO_BACKSLASH_ESCAPES` happens to be set to, so a typed
     * percent sign would stand for itself on a server configured one way and for anything at all
     * on another.
     *
     * It is not the backslash every other codebase names here, and for a reason that is ours: the
     * escape character travels to the server inside a string literal of the statement, and a
     * backslash in that literal is read one way with `NO_BACKSLASH_ESCAPES` off and another with
     * it on - the very thing the clause is here to settle. Our own binding is the second reason:
     * the database layer substitutes the parameters itself, and it reads a quote standing behind a
     * backslash as an escaped one, so `ESCAPE '\\'` would swallow every `?` of the rest of the
     * statement.
     */
    public const string LIKE_COMPARISON = "LIKE ? ESCAPE '!'";

    /** Prefix that makes the next character of a LIKE pattern stand for itself. */
    private const string ESCAPE_PREFIX = '!';

    /** What a bare `%` stands for inside a LIKE pattern: any run of characters, the empty one too. */
    private const string ANY_RUN = '%';

    /** What a bare `_` stands for inside a LIKE pattern: exactly one character. */
    private const string ANY_CHARACTER = '_';

    /** What a star a reader typed stands for in a field searched as a mask: any run of characters, the empty one too. */
    private const string MASK_ANY_RUN = '*';

    /** What any run of characters is written as inside a regular expression, a line break included under `s`. */
    private const string PATTERN_ANY_RUN = '.*';

    /** Delimiter of the expression a mask is compared by in memory, which the pieces of the term are quoted against. */
    private const string PATTERN_DELIMITER = '/';

    /**
     * Reads what a window asked to be searched for, or nothing at all.
     *
     * The edges of the term are trimmed and its inner spaces are kept: a space before a word is a
     * slip of the keyboard, while one between two words is part of the phrase being looked for. A
     * term of nothing but spaces is therefore no term, which is the same answer an empty field
     * gives and the same answer a window that carried no search at all gives.
     *
     * @param ?string $search Search term as the window carries it
     * @return ?string Term to search by, or null when the window asked for no search
     */
    public static function normalize(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $term = trim($search);

        return $term === '' ? null : $term;
    }

    /**
     * Builds the pattern a term matches by, with every character of it standing for itself.
     *
     * The match is a substring one - the term may sit anywhere in the value - so the pattern is the
     * term between two wildcards. Everything the term itself carries is escaped first, which is what
     * lets a reader find the row whose value really does contain a percent sign.
     *
     * A field searched as a mask is the one exception, and only while the term carries a star: the
     * pattern is then the whole value, every star turning into the wildcard of any run after the
     * escaping, so `agent-*.log` reads as `agent-%.log` and a typed percent sign still stands for
     * itself.
     *
     * @param string $search Term to search by, as {@see self::normalize()} read it
     * @param TableSearchMatch $match How the searched field is matched, a substring unless declared otherwise
     * @return string LIKE pattern for a column compared through {@see self::LIKE_COMPARISON}
     */
    public static function likePattern(string $search, TableSearchMatch $match = TableSearchMatch::Substring): string
    {
        $escaped = str_replace(
            [self::ESCAPE_PREFIX, self::ANY_RUN, self::ANY_CHARACTER],
            [
                self::ESCAPE_PREFIX . self::ESCAPE_PREFIX,
                self::ESCAPE_PREFIX . self::ANY_RUN,
                self::ESCAPE_PREFIX . self::ANY_CHARACTER,
            ],
            $search,
        );

        if (self::readsAsMask($search, $match)) {
            return str_replace(self::MASK_ANY_RUN, self::ANY_RUN, $escaped);
        }

        return self::ANY_RUN . $escaped . self::ANY_RUN;
    }

    /**
     * Builds the comparison a term makes in memory, for one field and the way that field is matched.
     *
     * It answers what {@see self::likePattern()} answers in the database, case aside on both sides
     * the way the database's collation sets it aside. The expression a mask needs is built once, here,
     * and not once per value: the comparison is run against every row of the set.
     *
     * @param string $search Term to search by, as {@see self::normalize()} read it
     * @param TableSearchMatch $match How the searched field is matched
     * @return Closure(string): bool Whether one value of the field answers the term
     */
    public static function matcher(string $search, TableSearchMatch $match): Closure
    {
        $needle = mb_strtolower($search);
        if (!self::readsAsMask($search, $match)) {
            return static fn(string $value): bool => str_contains(mb_strtolower($value), $needle);
        }

        $pieces = array_map(
            static fn(string $piece): string => preg_quote($piece, self::PATTERN_DELIMITER),
            explode(self::MASK_ANY_RUN, $needle),
        );
        $pattern = self::PATTERN_DELIMITER . '^' . implode(self::PATTERN_ANY_RUN, $pieces) . '$' . self::PATTERN_DELIMITER . 'su';

        return static fn(string $value): bool => preg_match($pattern, mb_strtolower($value)) === 1;
    }

    /**
     * Whether a term is read as a mask of the whole value rather than as a piece of it.
     *
     * @param string $search Term to search by
     * @param TableSearchMatch $match How the searched field is matched
     * @return bool Whether the field is a mask and the term carries a star
     */
    private static function readsAsMask(string $search, TableSearchMatch $match): bool
    {
        return $match === TableSearchMatch::Mask && str_contains($search, self::MASK_ANY_RUN);
    }
}
