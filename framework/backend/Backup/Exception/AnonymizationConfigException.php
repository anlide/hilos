<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

/**
 * Thrown when a restore's anonymization cannot be trusted to run as declared.
 *
 * Three families, all of them refusals rather than failures, and they differ in what has
 * already happened to the target database when they are raised:
 *
 * - a PII registry that does not describe a registry (a key naming no table, a value that
 *   is not a strategy, `purge` written on a column) - nothing has run;
 * - the coverage gate against the archive, before the first import: a table the registry
 *   never classified. The target database is still whatever it was;
 * - the compatibility gate against the live schema, after the import and the forward
 *   migrations and before the first row is rewritten: a declared column that is absent, or
 *   whose type, width, nullability or UNIQUE index cannot carry what its strategy writes.
 *
 * The last one is raised over a database that already holds the archive's data, so its
 * message has to say so - an operator who reads "refused" and walks away from a restored
 * production copy is exactly the outcome the whole feature exists to prevent.
 *
 * It extends {@see RestoreFailedException} rather than {@see BackupException} directly
 * because every raise of it ends the same restore run, and the restore path already
 * documents that family - a separate branch of the tree would make callers catch two
 * things to describe one outcome.
 */
class AnonymizationConfigException extends RestoreFailedException
{
}
