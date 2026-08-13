<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

/**
 * Thrown when a restore's anonymization cannot be trusted to run as declared.
 *
 * Two families, both of them refusals rather than failures: a PII registry that does not
 * describe a registry (a key naming no table, a value that is not a strategy, `purge`
 * written on a column), and the preflight gate against the archive schema (a table the
 * registry never classified, a column that is not in the dump, a strategy the column's
 * shape cannot carry).
 *
 * It extends {@see RestoreFailedException} rather than {@see BackupException} directly
 * because every raise of it ends the same restore run, before the first import, and the
 * restore path already documents that family - a separate branch of the tree would make
 * callers catch two things to describe one outcome.
 */
class AnonymizationConfigException extends RestoreFailedException
{
}
