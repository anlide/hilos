<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

/**
 * Axis of an Entity-vs-schema mismatch reported by {@see EntitySchemaAudit::audit()}.
 *
 * Each case names one independent way the Entity ORM metadata (_table, _columns,
 * _types, _primary, _indexes, _foreign) can diverge from the live table.
 */
enum EntitySchemaAxis: string
{
    /** The Entity's _table does not exist in the current schema. */
    case TABLE = 'table';

    /** keys(_types) and _columns disagree (a column has no type, or vice versa). */
    case META = 'meta';

    /** A column declared in _columns is absent from the live table. */
    case COLUMN_MISSING = 'column_missing';

    /**
     * A live column outside _columns is not insert-safe (not nullable, no
     * DEFAULT, not auto_increment, not GENERATED) so saveInsert() would break.
     */
    case COLUMN_NOT_INSERTABLE = 'column_not_insertable';

    /** The declared PhpType is not an accepted mapping for the live column type. */
    case TYPE = 'type';

    /** The live column type has no entry in the PhpType↔MySQL reference table. */
    case TYPE_UNSUPPORTED = 'type_unsupported';

    /** _primary and the live PRIMARY KEY disagree, or the PRIMARY KEY is missing. */
    case PRIMARY = 'primary';

    /** _indexes and the live secondary indexes disagree by name, columns, or uniqueness. */
    case INDEX = 'index';

    /** _foreign and the live foreign keys disagree. */
    case FOREIGN = 'foreign';
}
