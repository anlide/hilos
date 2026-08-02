<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

/**
 * Exception when a framework table the caller needs is absent from the project schema.
 *
 * Framework subsystems ship their tables as migration stubs, and a project activates
 * a subsystem by copying the stub into a numbered migration of its own. Reaching such
 * a table before that copy happened is a project setup gap, not an SQL fault, so the
 * message names the table and the exact file to copy instead of surfacing a bare
 * "table doesn't exist" from the driver.
 */
class TableNotActivatedException extends DatabaseRuntimeException
{
    /**
     * Creates exception naming the missing table and the stub that activates it.
     *
     * @param string $table Table that the project has not activated
     */
    public function __construct(string $table)
    {
        parent::__construct(
            "Table `{$table}` is not activated in this project: copy "
            . "framework/backend/Database/Migration/Stub/create_{$table}.sql "
            . 'into a project migration and apply it.',
        );
    }
}
