<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseParamsException;
use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Counts beside the options of a table's filters - how many rows each choice would leave.
 *
 * One way of counting serves every table, and the condition of the set stays the table's own: the
 * tally only decides WHICH sets are counted, and the table is handed each of them as a query and
 * counts it the way it serves a window. A second description of the WHERE written here for the
 * sake of the numbers would drift from the first, and the drift would show as a count beside an
 * option that the window, once picked, does not agree with.
 *
 * Each option is counted by a query of its own, stopped at the same ceiling the count of a window
 * stops at, so every number is either exact or plainly "at least" - never the spread of the first
 * few hundred rows passed off as the spread of the set. The price is a count per option, bounded by
 * {@see TableConstants::FACET_OPTION_LIMIT} options and by the ceiling on each.
 */
final class TableFacetTally
{
    /** Column the capped count is read back under. */
    private const string COUNT_COLUMN = 'cnt';

    /**
     * Counts the options of the filters a client asked about, each against the set without its own filter.
     *
     * An option is counted against the window's set with its own filter lifted and set to that
     * option, every other filter and the search still standing. Counted with its own filter in
     * place, every option but the chosen one would answer zero, and "how many would this leave"
     * would have no answer. The "any" count is the same set with the filter lifted and nothing put
     * in its place, which is what shows whether the filter narrowed anything at all.
     *
     * What is counted is a set and not a window, so the order, the size and the address of the
     * window are dropped before the table sees the query: none of them changes how many rows there
     * are, and an in-memory table would sort its whole set for every option only to count it.
     *
     * A filter offering more than {@see TableConstants::FACET_OPTION_LIMIT} options is left out
     * altogether, and so is a filter this table does not count, which the caller has already
     * narrowed the ask down to. The option's key is its value as text, the way the client writes it:
     * `true` and `false` as words, since PHP would write them as `1` and an empty string.
     *
     * A count that fails is the table's failure and passes through untouched: the tally raises
     * nothing of its own, and the table that wrote the count is the one that names what it raises.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search
     *     scoped by {@see ViewportTable::scopeSearch()}
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @param callable(TableQueryDTO): TableFacetCountDTO $countSet How the table counts the set a query describes
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by filter key
     */
    public static function forFilters(TableQueryDTO $query, array $wanted, callable $countSet): array
    {
        $set = new TableQueryDTO(
            search: $query->search,
            filter: $query->filter,
            searchableFields: $query->searchableFields,
        );

        $facets = [];
        foreach ($wanted as $filterKey => $values) {
            if (count($values) > TableConstants::FACET_OPTION_LIMIT) {
                continue;
            }

            // A filter key that reads as a number comes out of a PHP array as an integer.
            $key = (string) $filterKey;
            $base = $set->withoutFilter($key);
            $facet = [
                TableConstants::FACET_KEY_ANY => $countSet($base),
                TableConstants::FACET_KEY_OPTIONS => [],
            ];
            foreach ($values as $value) {
                $facet[TableConstants::FACET_KEY_OPTIONS][self::optionKey($value)] = $countSet($base->withFilter($key, $value));
            }

            $facets[$key] = $facet;
        }

        return $facets;
    }

    /**
     * Reads the options a client named out of a frame, keeping only what can be an option.
     *
     * An option value is a scalar: it is what the filter map is set to, and the filter map is what a
     * table turns into its condition. Anything else is dropped rather than refused, and so is a
     * filter whose entry is not a list at all - a malformed option costs its own number and nothing
     * more, a number beside an option being a decoration of a choice and not worth a frame.
     *
     * @param array<array-key, mixed> $facets Options by filter key, as the frame carried them
     * @return array<string, list<int|float|string|bool>> Options to count, by filter key
     */
    public static function wantedOptions(array $facets): array
    {
        $wanted = [];
        foreach ($facets as $filterKey => $values) {
            if (is_array($values)) {
                $wanted[$filterKey] = array_values(array_filter($values, is_scalar(...)));
            }
        }

        return $wanted;
    }

    /**
     * Counts the rows of a SQL set up to the ceiling, and says whether it got to the end of them.
     *
     * The rows are taken one past the ceiling inside a subquery and the subquery is counted, so the
     * database stops reading as soon as the answer is known to be "at least the ceiling" instead of
     * walking the whole set for a number nobody is shown.
     *
     * @param string $from Row source of the set, the text after `FROM` - one table or a join
     * @param string $where The ` WHERE ...` clause of the set, or an empty string for the whole source
     * @param list<mixed> $params Parameters bound to the placeholders of the clause, in order
     * @return TableFacetCountDTO Rows in the set, or the ceiling with the word that it stopped there
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When the count query fails
     */
    public static function cappedSqlCount(string $from, string $where, array $params): TableFacetCountDTO
    {
        $capped = TableConstants::COUNT_CEILING + 1;
        $sql = 'SELECT COUNT(*) AS ' . self::COUNT_COLUMN
            . ' FROM (SELECT 1 FROM ' . $from . $where . " LIMIT {$capped}) AS `capped`";
        $counted = (int) (Database::sql($sql, $params)->firstRow()[self::COUNT_COLUMN] ?? 0);
        $exact = $counted <= TableConstants::COUNT_CEILING;

        return new TableFacetCountDTO($exact ? $counted : TableConstants::COUNT_CEILING, $exact);
    }

    /**
     * Writes an option value as the text the client keys its option by.
     *
     * @param int|float|string|bool $value Option value as the client sent it
     * @return string Value as text, a boolean as a word
     */
    private static function optionKey(int|float|string|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
