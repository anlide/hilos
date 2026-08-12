<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Utils\Logger;

/**
 * The gate a client-chosen sort field passes before it can name anything in a query.
 *
 * A window's sort field is browser input, so nothing may build an identifier out of it
 * until a map declared in PHP has allowed it. The map is `wire name => column`, not a
 * plain list, because the two names are genuinely different vocabularies: the frontend
 * sorts by the row key it renders (`promptPiece`), the query orders by a column
 * (`prompt_piece`), and the map is where a table states the correspondence once.
 *
 * A rejected field is not an error the client sees: a name the table does not sort by
 * costs that window its ordering (the table's own default order stands) and a warning in
 * the log, which is the same outcome an honest name mismatch deserves and a hostile one
 * gets nothing more from.
 *
 * The same gate runs on two boundaries with two different maps — the table's declared
 * fields in {@see Definition\TableDefinition::getPage()} and the entity's real columns in
 * the ORM's page query — so a table that declares nothing is still not a way through to
 * a raw identifier.
 */
final class TableSortWhitelist
{
    /** Log context key: the boundary that rejected the field (table or entity class). */
    private const string LOG_KEY_CONTEXT = 'context';

    /** Log context key: the field name the client asked to sort by. */
    private const string LOG_KEY_FIELD = 'field';

    /** How much of the client's field name a rejection line carries, truncation mark included. */
    private const int LOGGED_FIELD_MAX_LENGTH = 100;

    /** Marks a field name the rejection line cut short. */
    private const string LOGGED_FIELD_TRUNCATION_SUFFIX = '...';

    /**
     * Resolves a requested sort against the fields a boundary allows.
     *
     * The lookup key is the column when one is already resolved and the field otherwise, so
     * a sort that passed the table's map is checked at the SQL boundary by the column it
     * earned rather than by the wire name that is nobody's column.
     *
     * @param ?TableSortDTO $sort Sort the window asked for, or null when it asked for none
     * @param array<string, string> $allowed Allowed `wire name => column` map; empty declares no opinion
     * @param string $context Boundary that owns the map, named in the rejection warning
     * @return ?TableSortDTO Sort carrying its allowed column, the unchanged input when the map is empty,
     *     or null when the field is not allowed
     */
    public static function resolve(?TableSortDTO $sort, array $allowed, string $context): ?TableSortDTO
    {
        if ($sort === null || $allowed === []) {
            return $sort;
        }

        $column = $allowed[$sort->column ?? $sort->field] ?? null;
        if ($column === null) {
            Logger::warning('Table sort field rejected', [
                self::LOG_KEY_CONTEXT => $context,
                self::LOG_KEY_FIELD => self::loggedField($sort->field),
            ]);

            return null;
        }

        return $sort->withColumn($column);
    }

    /**
     * Cuts a rejected field name down to what a log line should carry.
     *
     * The name is client input and a refusal is logged per window refresh, so a caller that
     * keeps asking with a long enough name would otherwise write the log rather than fill it.
     * What identifies the mistake is the start of the name, which survives the cut.
     *
     * @param string $field Field name as the client sent it
     * @return string Field name bounded to a log line's worth
     */
    private static function loggedField(string $field): string
    {
        if (mb_strlen($field) <= self::LOGGED_FIELD_MAX_LENGTH) {
            return $field;
        }

        return mb_substr($field, 0, self::LOGGED_FIELD_MAX_LENGTH - mb_strlen(self::LOGGED_FIELD_TRUNCATION_SUFFIX))
            . self::LOGGED_FIELD_TRUNCATION_SUFFIX;
    }
}
