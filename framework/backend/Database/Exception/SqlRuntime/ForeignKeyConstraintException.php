<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Cannot add or update a child row: a foreign key constraint fails.
 * MySQL Error: 1451, 1452
 */
class ForeignKeyConstraintException extends DatabaseRuntimeException
{
    /** @var int MySQL ER_ROW_IS_REFERENCED_2: a parent row is still referenced by a child row */
    public const int ROW_IS_REFERENCED_ERROR_CODE = 1451;

    /** @var list<int> */
    public const array MYSQL_ERROR_CODES = [self::ROW_IS_REFERENCED_ERROR_CODE, 1452];

    /** First `db`.`table` of a refused parent delete or update: the table whose row still references it. */
    private const string REFERENCING_TABLE_PATTERN = '/a foreign key constraint fails \(`[^`]*`\.`([^`]*)`/';

    /** A table name safe to keep and show: letters, digits and underscores only. */
    private const string SAFE_TABLE_NAME_PATTERN = '/\A[A-Za-z0-9_]+\z/';

    /**
     * The table whose row still references the parent row this statement tried to delete or update.
     *
     * Read only from a 1451 refusal; a refused child insert or update (1452) names no referencing table.
     *
     * @return ?string Referencing table name, or null when the refusal is not 1451, its text names no
     *     table, or the name holds anything but letters, digits and underscores
     */
    public function referencingTable(): ?string
    {
        if ($this->getMysqlErrorCode() !== self::ROW_IS_REFERENCED_ERROR_CODE
            || preg_match(self::REFERENCING_TABLE_PATTERN, $this->getMysqlErrorMessage(), $match) !== 1
            || preg_match(self::SAFE_TABLE_NAME_PATTERN, $match[1]) !== 1) {
            return null;
        }

        return $match[1];
    }
}
