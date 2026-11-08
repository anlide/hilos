<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

/**
 * Axis of an Entity-vs-schema mismatch reported by {@see EntitySchemaAudit}.
 *
 * ORM axes come from EntitySchemaAudit::audit(); moment storage axes are opt-in
 * through EntitySchemaAudit::auditMoments() and EntitySchemaAudit::auditProviderMoments().
 * TABLE_UNMAPPED runs the other direction — schema to Entity — and comes from
 * EntitySchemaAudit::auditTableCoverage().
 */
enum EntitySchemaAxis: string
{
    /** The Entity's _table does not exist in the current schema. */
    case TABLE = 'table';

    /**
     * A live table no audited Entity declares as its _table, absent from the
     * list of tables allowed to live without one.
     */
    case TABLE_UNMAPPED = 'table_unmapped';

    /** keys(_types) and _columns disagree (a column has no type, or vice versa). */
    case META = 'meta';

    /** A column declared in _columns is absent from the live table. */
    case COLUMN_MISSING = 'column_missing';

    /** A declared physical moment, including a DB-only one, is absent from the live table. */
    case MOMENT_COLUMN_MISSING = 'moment_column_missing';

    /** A standard moment's physical type is not signed BIGINT. */
    case MOMENT_TYPE = 'moment_type';

    /** A standard moment has a SQL current-time DEFAULT or ON UPDATE writer. */
    case MOMENT_AUTO_WRITE = 'moment_auto_write';

    /**
     * A live column outside _columns is not insert-safe (not nullable, no
     * DEFAULT, not auto_increment, not GENERATED) so saveInsert() would break.
     */
    case COLUMN_NOT_INSERTABLE = 'column_not_insertable';

    /** The declared PhpType is not an accepted mapping for the live column type. */
    case TYPE = 'type';

    /** The live column type has no entry in the PhpType↔MySQL reference table. */
    case TYPE_UNSUPPORTED = 'type_unsupported';

    /** A column declared in _columns has no instance property to hydrate into. */
    case PROPERTY_MISSING = 'property_missing';

    /**
     * The property's nullability contradicts the column's: it is nullable over a
     * NOT NULL column the database will not fill on its own, or non-nullable over
     * a NULL-able column.
     */
    case PROPERTY_NULLABLE = 'property_nullable';

    /** _primary and the live PRIMARY KEY disagree, or the PRIMARY KEY is missing. */
    case PRIMARY = 'primary';

    /**
     * _indexes and the live secondary indexes disagree by name, by uniqueness, by type, or by their
     * ordered columns - each of which carries the direction it is stored in, so an index that
     * kept its columns but lost a DESC is as much a divergence as one that lost a column.
     */
    case INDEX = 'index';

    /** _foreign and the live foreign keys disagree. */
    case FOREIGN = 'foreign';
}
