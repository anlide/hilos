<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

use Hilos\Backup\Anonymization\AnonymizationStartupGuard;
use Hilos\Backup\Anonymization\PiiRegistry;

/**
 * Thrown when a node carrying backup starts over a schema it could not anonymize.
 *
 * Raised by the startup gate ({@see AnonymizationStartupGuard}), so nothing has run and
 * nothing is at risk yet - that is the whole point of asking here. Three ways to earn it,
 * all of them the same refusal seen from a different distance: a table or a column of the
 * live schema that carries no verdict at all, a verdict that {@see PiiRegistry::collect()}
 * cannot make sense of, and a database whose schema cannot be read while backup claims it
 * would dump it.
 *
 * A sister of {@see RestoreFailedException} rather than one of its children, unlike
 * {@see AnonymizationConfigException}: that family describes a restore that is already
 * under way and names what has happened to the target database by the time it is raised.
 * Here there is no restore and no target - only a node declining to come up - and saying
 * "restore failed" would misinform both a reader and every catch written for that family.
 *
 * The reader is the author of the migration that added the unclassified table or column,
 * not the operator who started the node: the verdict is written in code, so the message
 * names every table and column at once and expects one edit to answer all of them.
 */
class UnclassifiedLiveSchemaException extends BackupException
{
}
