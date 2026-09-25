<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Utils\Logger;

/**
 * The gate a client-chosen sort order passes before it can name anything in a query.
 *
 * A window's order is browser input, so nothing may build an identifier out of it until a map
 * declared in PHP has allowed it. The map is `wire name => column`, not a plain list, because
 * the two names are genuinely different vocabularies: the frontend sorts by the row key it
 * renders (`promptPiece`), the query orders by a column (`prompt_piece`), and the map is where
 * a table states the correspondence once.
 *
 * A rejected order is not an error the client sees: a name the table does not sort by costs
 * that window its ordering (the table's own default order stands) and a warning in the log,
 * which is the same outcome an honest name mismatch deserves and a hostile one gets nothing
 * more from. An order is rejected whole, because half of a declared order is an order nobody
 * declared.
 *
 * The gate runs on two questions with two different answers behind them. {@see resolve()} asks
 * what a name turns into, and runs on both boundaries — the table's declared fields in
 * {@see TableDefinition::getPage()} and the entity's real columns in the ORM's page query — so
 * a table that declares nothing is still not a way through to a raw identifier.
 * {@see holdComposite()} asks whether an order of more than one column was offered at all, and
 * runs on the table boundary only: the ORM knows the columns of its entity and no declarations,
 * and declarations are what a composite order is held against. Offered means declared or the
 * mirror of a declared order — every direction turned — because the index under an order serves
 * its mirror by being read backwards, which is what a window going back asks of it already.
 */
final class TableSortWhitelist
{
    /** Log context key: the boundary that rejected the order (table or entity class). */
    private const string LOG_KEY_CONTEXT = 'context';

    /** Log context key: the order the client asked for, or the one a table declared. */
    private const string LOG_KEY_ORDER = 'order';

    /** Log context key: what made a declared order unusable. */
    private const string LOG_KEY_REASON = 'reason';

    /** Reason a declaration is skipped: it names a field the table's own map does not sort by. */
    private const string REASON_UNKNOWN_FIELD = 'unknown-field';

    /** Separates a component's field from its direction in a logged order. */
    private const string LOGGED_COMPONENT_SEPARATOR = ':';

    /** Separates two components of a logged order. */
    private const string LOGGED_ORDER_SEPARATOR = ', ';

    /** How much of the client's order a rejection line carries, truncation mark included. */
    private const int LOGGED_ORDER_MAX_LENGTH = 100;

    /** Marks an order the rejection line cut short. */
    private const string LOGGED_ORDER_TRUNCATION_SUFFIX = '...';

    /**
     * Resolves a requested order against the fields a boundary allows.
     *
     * The lookup key of a component is its column when one is already resolved and its field
     * otherwise, so an order that passed the table's map is checked at the SQL boundary by the
     * columns it earned rather than by the wire names that are nobody's columns.
     *
     * One component failing drops the whole order: the components are not independent asks but
     * one order, and serving the rest of it would quietly hand back a window ordered some other
     * way than the one asked for.
     *
     * @param ?TableSortOrderDTO $order Order the window asked for, or null when it asked for none
     * @param array<string, string> $allowed Allowed `wire name => column` map; empty declares no opinion
     * @param string $context Boundary that owns the map, named in the rejection warning
     * @return ?TableSortOrderDTO Order whose components carry their allowed columns, the unchanged input
     *     when the map is empty, or null when any component is not allowed
     */
    public static function resolve(?TableSortOrderDTO $order, array $allowed, string $context): ?TableSortOrderDTO
    {
        if ($order === null || $allowed === []) {
            return $order;
        }

        $resolved = [];
        foreach ($order->components as $component) {
            $column = $allowed[$component->column ?? $component->field] ?? null;
            if ($column === null) {
                Logger::warning('Table sort order rejected', [
                    self::LOG_KEY_CONTEXT => $context,
                    self::LOG_KEY_ORDER => self::loggedOrder($order),
                ]);

                return null;
            }

            $resolved[] = $component->withColumn($column);
        }

        return $order->withComponents($resolved);
    }

    /**
     * Holds an order of more than one column against the orders a table declared and their mirrors.
     *
     * A composite order is a declared capability, not something a client assembles: under every
     * declared one lies an index matching it in both its columns and their directions, and a
     * combination nobody indexed means the database sorts the whole filtered set on every show
     * of the window. So the match is exact — the same fields, the same directions, in the same
     * sequence — or the exact mirror of a declared order, every direction turned, and anything
     * else costs the window its ordering, the same as an unknown field does. The mirror is served
     * because it costs nothing: the index under an order serves its mirror by being read
     * backwards, and a window going back builds exactly that query today
     * ({@see TableWindowPlan::inverted()}). A partial mirror, some directions turned and others
     * not, is an order nobody indexed. An order of one component goes past untouched: which
     * single columns a table serves is what its field map already says.
     *
     * A declaration the table cannot honour is skipped as though it were not there, and says so
     * once per window rather than being repaired: a field outside the map has no column to
     * reach, so honouring such a declaration would promise an index that nothing stands behind.
     * Its mirror is skipped with it, for the same reason.
     *
     * @param ?TableSortOrderDTO $order Order the window asked for, or null when it asked for none
     * @param array<string, TableSortOrderDTO> $declared Orders the table offers, by the key it declared each under
     * @param array<string, string> $allowed Allowed `wire name => column` map of the same table
     * @param string $context Table that owns the declarations, named in the warnings
     * @return ?TableSortOrderDTO The order when it was offered, mirrors an offered one or names one column, null otherwise
     */
    public static function holdComposite(
        ?TableSortOrderDTO $order,
        array $declared,
        array $allowed,
        string $context,
    ): ?TableSortOrderDTO {
        if ($order === null || count($order->components) === 1) {
            return $order;
        }

        foreach ($declared as $candidate) {
            $reason = self::declarationFlaw($candidate, $allowed);
            if ($reason !== null) {
                Logger::warning('Table sort order declaration ignored', [
                    self::LOG_KEY_CONTEXT => $context,
                    self::LOG_KEY_ORDER => self::loggedOrder($candidate),
                    self::LOG_KEY_REASON => $reason,
                ]);

                continue;
            }

            if (self::isSameOrder($order, $candidate) || self::isMirroredOrder($order, $candidate)) {
                return $order;
            }
        }

        Logger::warning('Table sort order rejected', [
            self::LOG_KEY_CONTEXT => $context,
            self::LOG_KEY_ORDER => self::loggedOrder($order),
        ]);

        return null;
    }

    /**
     * Names what keeps a declared order from being offered, or null when nothing does.
     *
     * @param TableSortOrderDTO $declared Order the table declared
     * @param array<string, string> $allowed Allowed `wire name => column` map of the same table
     * @return ?string One of the REASON_* values, or null when the declaration is honoured
     */
    private static function declarationFlaw(TableSortOrderDTO $declared, array $allowed): ?string
    {
        foreach ($declared->components as $component) {
            if ($allowed !== [] && !isset($allowed[$component->field])) {
                return self::REASON_UNKNOWN_FIELD;
            }
        }

        return null;
    }

    /**
     * Tells whether two orders are the same order: the same fields, the same directions, in the same sequence.
     *
     * @param TableSortOrderDTO $asked Order the window asked for
     * @param TableSortOrderDTO $declared Order the table declared
     * @return bool Whether the window asked for exactly that declared order
     */
    private static function isSameOrder(TableSortOrderDTO $asked, TableSortOrderDTO $declared): bool
    {
        if (count($asked->components) !== count($declared->components)) {
            return false;
        }

        foreach ($asked->components as $index => $component) {
            $against = $declared->components[$index];
            if ($component->field !== $against->field || $component->direction !== $against->direction) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tells whether one order is the mirror of the other: the same fields in the same sequence, every direction turned.
     *
     * Every component has to be turned, not some: an order with only part of its directions
     * turned runs over a different index than the declared one, so it is an order nobody
     * declared, and it is refused as one.
     *
     * @param TableSortOrderDTO $asked Order the window asked for
     * @param TableSortOrderDTO $declared Order the table declared
     * @return bool Whether the window asked for exactly that declared order read backwards
     */
    private static function isMirroredOrder(TableSortOrderDTO $asked, TableSortOrderDTO $declared): bool
    {
        if (count($asked->components) !== count($declared->components)) {
            return false;
        }

        foreach ($asked->components as $index => $component) {
            $against = $declared->components[$index];
            $turned = $against->direction === TableConstants::ORDER_DESC
                ? TableConstants::ORDER_ASC
                : TableConstants::ORDER_DESC;
            if ($component->field !== $against->field || $component->direction !== $turned) {
                return false;
            }
        }

        return true;
    }

    /**
     * Writes an order out as the one line a log entry carries it on.
     *
     * The names are client input and a refusal is logged per window refresh, so a caller that
     * keeps asking with a long enough order would otherwise write the log rather than fill it.
     * What identifies the mistake is the start of the order, which survives the cut.
     *
     * @param TableSortOrderDTO $order Order as the client sent it, or as the table declared it
     * @return string Components as `field:direction`, bounded to a log line's worth
     */
    private static function loggedOrder(TableSortOrderDTO $order): string
    {
        $written = implode(self::LOGGED_ORDER_SEPARATOR, array_map(
            static fn(TableSortDTO $component): string
                => $component->field . self::LOGGED_COMPONENT_SEPARATOR . $component->direction,
            $order->components,
        ));

        if (mb_strlen($written) <= self::LOGGED_ORDER_MAX_LENGTH) {
            return $written;
        }

        return mb_substr($written, 0, self::LOGGED_ORDER_MAX_LENGTH - mb_strlen(self::LOGGED_ORDER_TRUNCATION_SUFFIX))
            . self::LOGGED_ORDER_TRUNCATION_SUFFIX;
    }
}
