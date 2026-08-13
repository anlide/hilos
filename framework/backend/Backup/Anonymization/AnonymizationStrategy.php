<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\BackupConstants;

/**
 * AnonymizationStrategy - what a restore does to one declared PII column, or to a
 * whole table.
 *
 * A project names one of these per column in its PII registry
 * ({@see BackupConstants::CATALOG_PII}); the framework owns what each one means in SQL
 * ({@see AnonymizationSqlBuilder}). Values are the strings a catalog is written in, so
 * they read as configuration rather than as PHP case names.
 *
 * `fake` is deliberately not a single case: faker-likeness is a set of formatters, and
 * guessing a column's meaning from its name is not something the framework may do - so
 * the project names the formatter it wants.
 */
enum AnonymizationStrategy: string
{
    /**
     * Replace with a salted SHA-256 of the value, truncated to fit the column.
     *
     * Equal inputs stay equal, so joins by value survive inside one restored copy; the
     * salt is minted per run and never stored, so two copies cannot be correlated.
     */
    case HASH = 'hash';

    /**
     * Replace with SQL NULL. Only legal on a nullable column - checked against the
     * archive schema before the first import.
     *
     * The case is spelled NULLIFY because `null` is a reserved word and cannot name a case.
     */
    case NULLIFY = 'null';

    /** Replace with a fixed stub string ({@see BackupConstants::ANONYMIZATION_MASK}). */
    case MASK = 'mask';

    /** Replace with `user<pk>@example.invalid` - undeliverable by RFC 2606, unique by primary key. */
    case FAKE_EMAIL = 'fake-email';

    /** Replace with `User <pk>`. */
    case FAKE_NAME = 'fake-name';

    /** Replace with a fictional-range phone number derived from the primary key. */
    case FAKE_PHONE = 'fake-phone';

    /**
     * Delete every row of the table. Declared on the table, not on a column - the only
     * case of its kind.
     *
     * The answer for token-shaped tables (sessions, push subscriptions), whose columns are
     * NOT NULL and UNIQUE: null is impossible there and a hash leaves a structurally valid
     * session or a live push address behind.
     */
    case PURGE = 'purge';

    /**
     * Tells whether this strategy derives its value from the row's primary key.
     *
     * The compatibility gate uses it: a table with a composite or absent primary key has
     * nothing single to derive from, so these strategies are refused on it.
     *
     * @return bool Whether the strategy needs a single-column primary key
     */
    public function needsPrimaryKey(): bool
    {
        return match ($this) {
            self::FAKE_EMAIL, self::FAKE_NAME, self::FAKE_PHONE => true,
            default => false,
        };
    }
}
