<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\Exception\AnonymizationConfigException;

/**
 * AnonymizationSqlBuilder - turns declared strategies into the SQL one restore runs.
 *
 * Every strategy is a set-expression evaluated by the database over the row it rewrites,
 * never a value computed in PHP and sent back: the rows have just been imported, there
 * may be millions of them, and reading them into the restoring process to write them
 * straight back would be the same work done twice with a network round trip between.
 *
 * Two invariants hold across all of them:
 *
 * - **NULL stays NULL.** A column that held nothing held no personal data either, and
 *   turning it into a hash or a fake name would invent a fact about the row. Every
 *   expression is wrapped in `CASE WHEN col IS NULL THEN NULL ELSE ... END`.
 * - **A replacement is derived, not drawn.** Hashes take a per-run salt, the `fake-*`
 *   family takes the row's primary key. Equal values stay equal, so joins by value and
 *   UNIQUE indexes both survive the pass - which random generation, faker-style, cannot
 *   promise.
 */
final class AnonymizationSqlBuilder
{
    /** Result column {@see maxPrimaryKeyStatement()} returns its number under. */
    public const string MAX_PRIMARY_KEY_ALIAS = 'max_primary_key';

    /** SHA-256 as {@see AnonymizationStrategy::HASH} asks the database for it. */
    private const int HASH_BITS = 256;

    /** Hex characters {@see HASH_BITS} renders to; a column at least this wide needs no truncation. */
    private const int HASH_HEX_LENGTH = 64;

    /** Local part prefix of a faked address; the row's primary key follows it. */
    private const string FAKE_EMAIL_PREFIX = 'user';

    /**
     * Domain of a faked address. RFC 2606 reserves `.invalid` as never resolvable, so a
     * staging system that decides to send mail cannot reach a real person with it.
     */
    private const string FAKE_EMAIL_DOMAIN = '@example.invalid';

    /** Prefix of a faked display name; the row's primary key follows it. */
    private const string FAKE_NAME_PREFIX = 'User ';

    /**
     * Country and area code of a faked phone number: the NANP `555` exchange is reserved
     * for fiction and reaches nobody.
     */
    private const string FAKE_PHONE_PREFIX = '+1555';

    /** Digits the primary key is padded to inside a faked phone number. */
    private const int FAKE_PHONE_DIGITS = 7;

    /** Pad character of a faked phone number's subscriber part. */
    private const string FAKE_PHONE_PAD = '0';

    /**
     * @param string $salt Per-run hash salt; hexadecimal, so it carries nothing to escape
     */
    public function __construct(private readonly string $salt)
    {
    }

    /**
     * Builds the statement that empties a purged table.
     *
     * `DELETE`, not `TRUNCATE`, and the reason is rollback rather than foreign keys: a
     * `TRUNCATE` commits implicitly, so it would survive a pass that fails two tables later
     * and leave the connection's one transaction meaning nothing. (Neither statement gets
     * past a RESTRICT child row - a parent table declared for purge fails the pass either
     * way, and the schema reader does not read key clauses, so the gate cannot warn first.)
     *
     * @param string $table Table to empty
     * @return string SQL statement
     */
    public function purgeStatement(string $table): string
    {
        return 'DELETE FROM ' . self::quoteIdentifier($table);
    }

    /**
     * Builds the one statement that rewrites every declared column of a table.
     *
     * One `UPDATE` per table rather than one per column: the columns are independent of
     * each other, and a single pass over the table does the work of N.
     *
     * @param LiveTableSchema $schema Table as the live database declares it
     * @param array<string, AnonymizationStrategy> $strategies Declared column strategies
     * @return ?string SQL statement, or null when the table declares no column to rewrite
     * @throws AnonymizationConfigException When a strategy cannot be expressed for its column;
     *     the preflight gate refuses these before a restore reaches this point
     */
    public function updateStatement(LiveTableSchema $schema, array $strategies): ?string
    {
        $assignments = [];
        foreach ($strategies as $column => $strategy) {
            $assignments[] = self::quoteIdentifier($column) . ' = '
                . $this->columnExpression($strategy, $column, $schema);
        }

        if ($assignments === []) {
            return null;
        }

        return 'UPDATE ' . self::quoteIdentifier($schema->table) . ' SET ' . implode(', ', $assignments);
    }

    /**
     * Builds the value one strategy writes into one column.
     *
     * @param AnonymizationStrategy $strategy Strategy declared for the column
     * @param string $column Column being rewritten
     * @param LiveTableSchema $schema Table as the live database declares it
     * @return string SQL expression
     * @throws AnonymizationConfigException When the strategy is table-level, or needs a single
     *     primary key the table does not have
     */
    public function columnExpression(
        AnonymizationStrategy $strategy,
        string $column,
        LiveTableSchema $schema,
    ): string {
        $quoted = self::quoteIdentifier($column);

        return match ($strategy) {
            // The only strategy that writes rather than rewrites, so the NULL guard the
            // others share would say "leave nothing as nothing" and mean nothing.
            AnonymizationStrategy::NULLIFY => 'NULL',
            AnonymizationStrategy::HASH => self::preservingNull(
                $quoted,
                $this->hashExpression($quoted, $schema->lengthOf($column)),
            ),
            AnonymizationStrategy::MASK => self::preservingNull(
                $quoted,
                self::quoteLiteral(BackupConstants::ANONYMIZATION_MASK),
            ),
            AnonymizationStrategy::FAKE_EMAIL => self::preservingNull($quoted, self::concat(
                self::quoteLiteral(self::FAKE_EMAIL_PREFIX),
                self::primaryKeyOf($schema, $column),
                self::quoteLiteral(self::FAKE_EMAIL_DOMAIN),
            )),
            AnonymizationStrategy::FAKE_NAME => self::preservingNull($quoted, self::concat(
                self::quoteLiteral(self::FAKE_NAME_PREFIX),
                self::primaryKeyOf($schema, $column),
            )),
            AnonymizationStrategy::FAKE_PHONE => self::preservingNull($quoted, self::concat(
                self::quoteLiteral(self::FAKE_PHONE_PREFIX),
                'LPAD(' . self::primaryKeyOf($schema, $column) . ', ' . self::FAKE_PHONE_DIGITS
                    . ', ' . self::quoteLiteral(self::FAKE_PHONE_PAD) . ')',
            )),
            AnonymizationStrategy::PURGE => throw new AnonymizationConfigException(
                "[{$strategy->value}] empties a table and cannot rewrite column "
                . "[{$schema->table}.{$column}]",
            ),
        };
    }

    /**
     * Returns the widest value a strategy can write, so a gate can ask whether it fits.
     *
     * Answered here rather than in the gate because the form of a replacement is this
     * class's own knowledge: every literal it prefixes and every digit it pads to is a
     * constant above, and a second place counting those characters would be a copy that
     * silently stops agreeing with the SQL.
     *
     * {@see AnonymizationStrategy::HASH} answers null and means it: it truncates itself to
     * whatever the column takes ({@see hashExpression()}), so no column is too narrow for
     * it. So do the strategies that write no characters at all.
     *
     * @param AnonymizationStrategy $strategy Strategy declared for a column
     * @param int $maxPrimaryKey Largest primary key value the table currently holds; the
     *     `fake-*` family renders it into the value
     * @return ?int Characters the widest replacement takes, or null when no width can be
     *     exceeded
     */
    public static function substitutionLength(AnonymizationStrategy $strategy, int $maxPrimaryKey): ?int
    {
        $keyDigits = strlen((string)$maxPrimaryKey);

        return match ($strategy) {
            AnonymizationStrategy::HASH,
            AnonymizationStrategy::NULLIFY,
            AnonymizationStrategy::PURGE => null,
            AnonymizationStrategy::MASK => strlen(BackupConstants::ANONYMIZATION_MASK),
            AnonymizationStrategy::FAKE_EMAIL => strlen(self::FAKE_EMAIL_PREFIX) + $keyDigits
                + strlen(self::FAKE_EMAIL_DOMAIN),
            AnonymizationStrategy::FAKE_NAME => strlen(self::FAKE_NAME_PREFIX) + $keyDigits,
            // LPAD cuts a longer key down rather than growing the value, so the width of a
            // faked phone number is the same for every row of every table.
            AnonymizationStrategy::FAKE_PHONE => strlen(self::FAKE_PHONE_PREFIX) + self::FAKE_PHONE_DIGITS,
        };
    }

    /**
     * Builds the query that reads the largest primary key a table currently holds.
     *
     * Kept beside the statements it serves rather than at the caller: it is SQL over
     * identifiers that came out of a schema, and quoting those is this class's job.
     *
     * @param string $table Table to read
     * @param string $column Its single primary key column
     * @return string SQL statement returning one row, under {@see MAX_PRIMARY_KEY_ALIAS}
     */
    public static function maxPrimaryKeyStatement(string $table, string $column): string
    {
        return 'SELECT MAX(' . self::quoteIdentifier($column) . ') AS '
            . self::quoteIdentifier(self::MAX_PRIMARY_KEY_ALIAS)
            . ' FROM ' . self::quoteIdentifier($table);
    }

    /**
     * Wraps a replacement so a column that held nothing keeps holding nothing.
     *
     * @param string $quotedColumn Quoted column being rewritten
     * @param string $replacement Expression written in place of a non-null value
     * @return string SQL expression
     */
    private static function preservingNull(string $quotedColumn, string $replacement): string
    {
        return "CASE WHEN {$quotedColumn} IS NULL THEN NULL ELSE {$replacement} END";
    }

    /**
     * Builds a salted hash that still fits the column it is written back into.
     *
     * @param string $quotedColumn Quoted column being hashed
     * @param ?int $length Declared character length of the column, when it has one
     * @return string SQL expression
     */
    private function hashExpression(string $quotedColumn, ?int $length): string
    {
        $hash = 'SHA2(' . self::concat(self::quoteLiteral($this->salt), $quotedColumn)
            . ', ' . self::HASH_BITS . ')';
        if ($length === null || $length >= self::HASH_HEX_LENGTH) {
            return $hash;
        }

        return "LEFT({$hash}, {$length})";
    }

    /**
     * Returns the quoted primary key column a `fake-*` strategy derives from.
     *
     * @param LiveTableSchema $schema Table as the live database declares it
     * @param string $column Column being rewritten, named in the refusal
     * @return string Quoted primary key column
     * @throws AnonymizationConfigException When the table has no single primary key column
     */
    private static function primaryKeyOf(LiveTableSchema $schema, string $column): string
    {
        $primaryKey = $schema->singlePrimaryKey();
        if ($primaryKey === null) {
            throw new AnonymizationConfigException(
                "Column [{$schema->table}.{$column}] takes a derived value, but the table "
                . 'declares no single primary key column to derive it from',
            );
        }

        return self::quoteIdentifier($primaryKey);
    }

    /**
     * @param string ...$parts Expressions to join
     * @return string `CONCAT(...)` over the parts
     */
    private static function concat(string ...$parts): string
    {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /**
     * Quotes a table or column name for MySQL.
     *
     * @param string $identifier Table or column name
     * @return string Backtick-quoted identifier
     */
    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Quotes a string literal for MySQL.
     *
     * The literals here are framework constants and a hexadecimal salt rather than
     * anything a row carries, so this is belt over braces - but a constant is one edit
     * away from carrying an apostrophe, and the edit would not look dangerous.
     *
     * @param string $value Literal value
     * @return string Single-quoted literal
     */
    private static function quoteLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }
}
