<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;

/**
 * TablesWithoutEntityProvider - what an installation says about its tables outside the ORM.
 *
 * A table with an Entity behind it answers both questions on the Entity itself: the
 * Entity names the table, and its `_pii` / `_piiNotPersonal` constants carry the
 * personal-data verdict. A table written by code that runs before or beneath an Entity
 * has nowhere to put either, so it says the same two things here instead - once for the
 * schema audit, which asks which live tables are unmapped on purpose, and once for
 * {@see PiiRegistry}, which asks what a restore must rewrite.
 *
 * Implemented by {@see FrameworkTablesWithoutEntity} for the tables the framework ships;
 * a project with tables of its own implements it too and names the class under
 * `BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY` in its backup catalog.
 */
interface TablesWithoutEntityProvider
{
    /**
     * Names the tables that live outside the ORM on purpose.
     *
     * @return list<string> Table names, unordered
     */
    public static function tables(): array;

    /**
     * Returns the personal-data verdict of each of those tables.
     *
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Table name to its
     *     per-column strategies, or to {@see AnonymizationStrategy::PURGE} for a table emptied whole
     */
    public static function pii(): array;

    /**
     * Returns the columns of those tables looked at and found to hold no personal data.
     *
     * @return array<string, list<string>> Table name to its non-personal columns; a purged table
     *     names none, because no row of it survives
     */
    public static function piiNotPersonal(): array;
}
