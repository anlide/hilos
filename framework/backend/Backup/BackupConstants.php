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

    /**
     * Backup catalog key under which a project declares its backup schedule.
     *
     * The value at this key is `list<array<string, mixed>>`: one entry per scheduled backup,
     * each `{name, cron, scope, mechanism}`. {@see BackupSchedule} reads it; an absent or
     * empty schedule falls back to the single default entry
     * ({@see DEFAULT_SCHEDULE_NAME}/{@see DEFAULT_SCHEDULE_CRON}).
     */
    public const string CATALOG_SCHEDULE = 'schedule';

    /** Schedule entry key: the unique job name (also the daemon-mechanism cron signal name). */
    public const string SCHEDULE_NAME = 'name';

    /** Schedule entry key: the five-field cron expression (server timezone). */
    public const string SCHEDULE_CRON = 'cron';

    /** Schedule entry key: the {@see BackupScope} value the run captures. */
    public const string SCHEDULE_SCOPE = 'scope';

    /** Schedule entry key: the {@see BackupScheduleMechanism} value; absent means agent. */
    public const string SCHEDULE_MECHANISM = 'mechanism';

    /** Default schedule entry name used when a project declares no schedule. */
    public const string DEFAULT_SCHEDULE_NAME = 'daily-full';

    /** Default schedule cron: a daily full backup at 03:00 server time. */
    public const string DEFAULT_SCHEDULE_CRON = '0 3 * * *';
}
