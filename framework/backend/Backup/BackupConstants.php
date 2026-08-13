<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Runtime\State\Item\RestoreRuntime;

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
     * Command-channel wire name routing a forced retention prune to {@see Agent\BackupAgent}
     * (test-only `test:backup:prune`, HIL-320). Declared on the agent's AGENT_COMMANDS.
     */
    public const string PRUNE_COMMAND = 'backup:prune';

    /**
     * Command-channel wire name routing a forced scheduled backup to {@see Agent\BackupAgent}
     * (test-only `test:backup:run-schedule`, HIL-320). Declared on the agent's AGENT_COMMANDS.
     */
    public const string RUN_SCHEDULE_COMMAND = 'backup:run-schedule';

    /**
     * Command-channel wire name asking {@see Agent\BackupAgent} to re-mirror its runtime index
     * from storage. Declared on the agent's AGENT_COMMANDS.
     *
     * Not test-only: the operator command `backup:verify` rewrites sidecars on disk (files=truth)
     * and then asks the agent to catch its index up, so the admin list shows the verification
     * without waiting for the next rescan or restart.
     *
     * Provisional: HIL-528 gives the daemon filesystem watching, after which the index catches
     * up on its own however storage changed, and this per-writer poke goes away.
     */
    public const string REFRESH_HISTORY_COMMAND = 'backup:refresh-history';

    /** Reply key: number of index rows the agent holds after re-mirroring. */
    public const string FIELD_HISTORY_COUNT = 'historyCount';

    /** `--at=<ISO-8601>` option: the explicit createdAt the age fixture writes into a sidecar. */
    public const string AT_OPTION = 'at';

    /** `--days=<N>` option: the age fixture writes a createdAt N days before now. */
    public const string DAYS_OPTION = 'days';

    /** Reply key: number of backups the forced prune removed. */
    public const string FIELD_PRUNED_COUNT = 'prunedCount';

    /** Request payload key: the schedule entry name a forced run-schedule resolves to a scope. */
    public const string FIELD_SCHEDULE_NAME = 'name';

    /** Reply key: the id of the backup a forced run-schedule started. */
    public const string FIELD_BACKUP_ID = 'backupId';

    /** Reply key: the scope value of the backup a forced run-schedule started. */
    public const string FIELD_SCOPE = 'scope';

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

    /**
     * Backup catalog key under which a project declares which of its data is personal.
     *
     * The value at this key is
     * `array<int, array<class-string|string, array<string, AnonymizationStrategy>|AnonymizationStrategy>>`:
     * per connection index, one row per table. The row key is the table's Entity or Object
     * collection class wherever one exists (a raw table name only where none does), and the
     * row is either a map of column name to {@see Anonymization\AnonymizationStrategy}, or
     * {@see Anonymization\AnonymizationStrategy::PURGE} for a table emptied whole.
     *
     * An empty map is the row that says "this table holds no personal data" - the registry
     * has no second key listing clean tables, because a table nobody wrote a row for must
     * stay indistinguishable from a table nobody thought about. {@see Anonymization\PiiRegistry}
     * reads the key, and every table of a restored archive has to appear in it.
     */
    public const string CATALOG_PII = 'pii';

    /**
     * Replacement written by {@see Anonymization\AnonymizationStrategy::MASK}.
     *
     * Deliberately legible rather than random: a developer reading a restored staging row
     * has to see that the value was removed, not wonder what it used to say.
     */
    public const string ANONYMIZATION_MASK = '[redacted]';

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

    /**
     * Command-channel wire name asking {@see Agent\BackupAgent} to run a restore (HIL-274).
     * Declared on the agent's AGENT_COMMANDS; the reply is accepted/refused, the restore
     * itself runs asynchronously under protected mode.
     */
    public const string RESTORE_REQUEST_COMMAND = 'backup:restore-request';

    /**
     * Command-channel wire name asking {@see Agent\BackupAgent} for a snapshot of the
     * restore runtime row; the CLI monitor polls it until the run reaches a terminal
     * outcome. Declared on the agent's AGENT_COMMANDS.
     */
    public const string RESTORE_STATUS_COMMAND = 'backup:restore-status';

    /**
     * CLI command name the restore supervisor spawns as its child
     * (`php <cli> backup:restore-run <id> --scope= --decision=`); the project registers a
     * command under this name, exactly as it does for {@see RUN_COMMAND}.
     */
    public const string RESTORE_RUN_COMMAND = 'backup:restore-run';

    /**
     * Restore status reply keys, bound to the runtime row's own field names
     * ({@see RestoreRuntime}): the reply is that row's snapshot, and a separately spelled
     * wire key would be the same concept free to drift.
     */
    public const string FIELD_RESTORE_PHASE = RestoreRuntime::phase;
    public const string FIELD_RESTORE_OUTCOME = RestoreRuntime::outcome;
    public const string FIELD_RESTORE_FAILURE = RestoreRuntime::failureReason;
    public const string FIELD_RESTORE_RUNNING = RestoreRuntime::running;
    public const string FIELD_REHYDRATE_COMPLETE = RestoreRuntime::rehydrateComplete;
    public const string FIELD_REHYDRATE_PROBLEMS = RestoreRuntime::rehydrateProblems;
    public const string FIELD_DATABASE_TOUCHED = RestoreRuntime::databaseTouched;

    /**
     * Exit code of the restore child when it failed without touching the database (HIL-436).
     *
     * A code of its own, because the two failures ask different things of the operator: a run that
     * refused or died over the archive left the system exactly as it was, while any other failure
     * may have left it half-overwritten. The supervisor cannot tell them apart from a generic
     * error, and the difference is the first thing the person who has to act on it needs.
     */
    public const int RESTORE_EXIT_DATABASE_INTACT = 7;

    /**
     * Request payload / child argv option carrying the {@see RestoreEnvDecision} value the
     * CLI preflight recorded; the engine acts on it without re-deriving.
     */
    public const string FIELD_DECISION = 'decision';

    /** `--force` option: override the unknown-archive-env-into-prod refusal (HIL-269 envs). */
    public const string FORCE_OPTION = 'force';

    /** `--cold` option: run the restore engine synchronously in the CLI, without the daemon. */
    public const string COLD_OPTION = 'cold';

    /** `--yes` option: the explicit confirmation a destructive restore requires on both paths. */
    public const string YES_OPTION = 'yes';
}
