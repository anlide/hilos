<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupConstants - the cross-process vocabulary shared by the backup supervisor and child.
 *
 * The supervisor spawns `php <cli> backup:run <id> --scope=<scope>`; the project registers
 * a CLI command under {@see RUN_COMMAND} and parses {@see SCOPE_OPTION}. Both sides read
 * these constants so the argv the supervisor builds and the name/option the child expects
 * can never drift apart.
 */
final class BackupConstants
{
    /** CLI command name the supervisor spawns; the project registers a command under this name. */
    public const string RUN_COMMAND = 'backup:run';

    /** `--scope` option name shared by the supervisor's argv and the child command parser. */
    public const string SCOPE_OPTION = 'scope';

    /**
     * Backup catalog key under which a project declares its reference-object registry.
     *
     * The value at this key is `array<int, list<class-string>>`: reference/seed Entity or
     * Object collection classes keyed by connection index. {@see BackupReferenceRegistry}
     * reads it to keep those tables' rows under the schema-seed scope.
     */
    public const string CATALOG_REFERENCES = 'references';
}
