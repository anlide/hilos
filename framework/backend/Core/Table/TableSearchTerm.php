<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * The one reading of a window's search term: what counts as a term, and what it matches.
 *
 * Three paths search a table - the ORM's page query, a table's own SQL and the in-memory filter -
 * and each of them used to answer both questions for itself. They disagreed on the same input: the
 * delivery log trimmed the edges of the term and the ORM did not, and a percent sign somebody typed
 * meant "anything at all" in the database and a percent sign in memory. One reading here is what
 * makes the answer the same wherever the rows are read from.
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
     * The match stays a substring one - the term may sit anywhere in the value - so the pattern is
     * the term between two wildcards. Everything the term itself carries is escaped first, which is
     * what lets a reader find the row whose value really does contain a percent sign.
     *
     * @param string $search Term to search by, as {@see self::normalize()} read it
     * @return string LIKE pattern for a column compared through {@see self::LIKE_COMPARISON}
     */
    public static function likePattern(string $search): string
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

        return self::ANY_RUN . $escaped . self::ANY_RUN;
    }
}
