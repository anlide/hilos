<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * SQL index type keywords matching `information_schema.STATISTICS.INDEX_TYPE` in MariaDB 11.4.
 *
 * Values are used directly in `_indexes` declarations and checked string-for-string against the
 * database during schema audits. A spatial index is written in DDL as `SPATIAL`, but declared
 * here as `RTREE` because that is the name the database uses in `INDEX_TYPE`.
 */
final class SqlIndexType
{
    /** B-tree index, default index type for InnoDB tables. */
    public const string BTREE = 'BTREE';

    /** Full-text search index (`FULLTEXT`). */
    public const string FULLTEXT = 'FULLTEXT';

    /** Hash index (`HASH`). */
    public const string HASH = 'HASH';

    /** Spatial index (`RTREE`, created as `SPATIAL` in DDL). */
    public const string RTREE = 'RTREE';

    /**
     * Tells whether an index of this type can answer an equality lookup by value (`column = ?`).
     *
     * A join reads by asking for rows whose column equals a value, and only BTREE and HASH
     * indexes can answer that equality lookup; FULLTEXT and RTREE cannot.
     *
     * @param string $type One of the index type constants
     * @return bool True when the index type can answer an equality lookup
     */
    public static function answersEqualityLookup(string $type): bool
    {
        return $type === self::BTREE || $type === self::HASH;
    }
}
