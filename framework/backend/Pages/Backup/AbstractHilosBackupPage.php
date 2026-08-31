<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestoreEnvGuard;
use Hilos\Backup\RestoreMigrationGuard;
use Hilos\Backup\RestoreUiGate;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Hilos;
use Hilos\Pages\Backup\DTO\BackupCreateActionDTO;
use Hilos\Pages\Backup\DTO\BackupDeleteActionDTO;
use Hilos\Pages\Backup\DTO\BackupRestoreActionDTO;
use Hilos\Pages\Backup\DTO\BackupSetKeepActionDTO;
use Hilos\Runtime\View\Collection\BackupHistories;

/**
 * AbstractHilosBackupPage - Abstract base for the Hilos backup list page.
 *
 * Owns the backup subscribe signal and the create / delete / toggle-keep action
 * lifecycle. It never writes to runtime state or files itself (single-writer rule,
 * files=truth): each action is validated synchronously against the runtime index
 * (so an invalid target fails the client's request with a correlated ACTION_ERROR)
 * and then routed to the monopoly {@see BackupAgent} through
 * `$this->agent->sendToAgent(...)`, which owns the storage mutation. The generic
 * action ack (ACTION_SUCCESS / ACTION_ERROR, correlated by requestId) reports
 * acceptance; the committed outcome is observed reactively over the live table.
 *
 * A create outlives its ack — a dump runs far longer than any reply window — so the
 * requester's accept key travels with it and the agent addresses the failure back to
 * that connection when the run ends ({@see BackupAgent}). A run nobody asked for
 * (schedule, CLI) reports to nobody and is read from the list instead.
 *
 * Projects must implement a concrete subclass (e.g.
 * Demo\Chat\Pages\Hilos\Backup\BackupPage) with a `SUBSCRIPTION_AGENT_TYPE`; they
 * add no action code of their own.
 */
abstract class AbstractHilosBackupPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BACKUP;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::BACKUP_CREATE => BackupCreateActionDTO::class,
        HilosSignalConstants::BACKUP_DELETE => BackupDeleteActionDTO::class,
        HilosSignalConstants::BACKUP_SET_KEEP => BackupSetKeepActionDTO::class,
        HilosSignalConstants::BACKUP_RESTORE => BackupRestoreActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BACKUP,
    ];

    /** Page-data section carrying what the page may offer for restoring. */
    public const string RESTORE_SECTION = 'backupRestore';

    /** Restore section key: whether this environment offers the restore button at all. */
    public const string RESTORE_UI_ENABLED = 'uiEnabled';

    /** Restore section key: the environment this installation runs in, as the modal names it. */
    public const string RESTORE_TARGET_ENV = 'targetEnv';

    /**
     * Routes backup create, delete, set-keep, and restore actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the action target or state is invalid
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     * @throws EnvInvalidValueException When catalog metadata or the backup value is invalid
     * @throws EnvKeyInvalidException When the key is invalid
     * @throws EnvNotInCatalogException When the key is not declared in the catalog
     * @throws EnvTypeMismatchException When the key is not cataloged as the type read
     * @throws MissingEnvironmentVariableException When a required value is missing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::BACKUP_CREATE:
                if (!$dto instanceof BackupCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, BackupCreateActionDTO::class, $dto);
                }
                $this->handleCreate($acceptKey, $dto);

                break;

            case HilosSignalConstants::BACKUP_DELETE:
                if (!$dto instanceof BackupDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, BackupDeleteActionDTO::class, $dto);
                }
                $this->handleDelete($acceptKey, $dto);

                break;

            case HilosSignalConstants::BACKUP_SET_KEEP:
                if (!$dto instanceof BackupSetKeepActionDTO) {
                    throw new InvalidActionPayloadException($action, BackupSetKeepActionDTO::class, $dto);
                }
                $this->handleSetKeep($acceptKey, $dto);

                break;

            case HilosSignalConstants::BACKUP_RESTORE:
                if (!$dto instanceof BackupRestoreActionDTO) {
                    throw new InvalidActionPayloadException($action, BackupRestoreActionDTO::class, $dto);
                }
                $this->handleRestore($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Validates the scope and routes a create to the backup agent's guarded path.
     *
     * A busy agent is not an error: the design accepts a manual create while one is
     * running into a single in-memory pending slot, so acceptance is always acked.
     *
     * The ack answers acceptance, never the run: a dump takes as long as it takes,
     * far past the client's reply timeout, so the action must not be held open for it.
     * The requester's accept key rides along instead, and the agent addresses the
     * outcome back to that connection once the run ends.
     *
     * A misconfigured install is an error, and a synchronous one: the same precondition the
     * agent enforces ({@see BackupAgent::missingCreateConfig()}) is checked here so the click
     * fails with a correlated ACTION_ERROR instead of being accepted into a run that can never
     * report back - the agent would refuse it, and with no storage root even the error record
     * has nowhere to go.
     *
     * @param string $acceptKey Accept key of the requesting connection, told the run's outcome
     * @param BackupCreateActionDTO $dto Create action payload
     * @throws TableActionException When the scope is not a known backup scope, or backup storage
     *     is not configured
     */
    private function handleCreate(string $acceptKey, BackupCreateActionDTO $dto): void
    {
        $scope = BackupScope::fromString($dto->scope);
        if ($scope === null) {
            throw new TableActionException("Invalid backup scope: {$dto->scope}");
        }

        $missing = BackupAgent::missingCreateConfig(
            Hilos::$env->string(EnvConstants::BACKUP_DIR),
            Hilos::$env->string(EnvConstants::BACKUP_CLI_ENTRY),
        );
        if ($missing !== []) {
            throw new TableActionException('Backups are not configured: ' . implode(', ', $missing));
        }

        $this->agent->sendToAgent(
            HilosSignalConstants::BACKUP_AGENT_CREATE,
            new BackupCreateSignalData($scope->value, $acceptKey),
        );
    }

    /**
     * Validates the target and routes a delete to the backup agent's shared delete path.
     *
     * Deleting the in-progress backup is rejected; deleting an already-removed backup
     * is not (the agent no-ops it), so a permissive delete stays idempotent.
     *
     * The requester's accept key rides along so the agent stamps it as the origin of
     * the index write and this tab applies its own row removal at once.
     *
     * @param string $acceptKey Accept key of the requesting connection
     * @param BackupDeleteActionDTO $dto Delete action payload
     * @throws TableActionException When the id is empty or names the in-progress backup
     */
    private function handleDelete(string $acceptKey, BackupDeleteActionDTO $dto): void
    {
        if ($dto->backupId === '') {
            throw new TableActionException('Backup id is required');
        }
        if ($this->isInProgress($dto->backupId)) {
            throw new TableActionException('Cannot delete a backup that is in progress');
        }

        $this->agent->sendToAgent(
            HilosSignalConstants::BACKUP_AGENT_DELETE,
            new BackupDeleteSignalData($dto->backupId, $acceptKey),
        );
    }

    /**
     * Validates the target and routes a keep toggle to the backup agent.
     *
     * Only a successful, completed backup can be pinned; an in-progress or error
     * record is rejected with a correlated ACTION_ERROR.
     *
     * The requester's accept key rides along so the agent stamps it as the origin of
     * the re-mirror write and this tab applies its own row update at once.
     *
     * @param string $acceptKey Accept key of the requesting connection
     * @param BackupSetKeepActionDTO $dto Set-keep action payload
     * @throws TableActionException When the id is empty, missing, in progress, or not a success
     */
    private function handleSetKeep(string $acceptKey, BackupSetKeepActionDTO $dto): void
    {
        if ($dto->backupId === '') {
            throw new TableActionException('Backup id is required');
        }
        if ($this->isInProgress($dto->backupId)) {
            throw new TableActionException('Cannot pin a backup that is in progress');
        }

        $histories = $this->histories();
        $row = $histories === null ? null : $histories[$dto->backupId];
        if ($row === null) {
            throw new TableActionException("Backup not found: {$dto->backupId}");
        }
        if (BackupStatus::fromString($row->status) !== BackupStatus::SUCCESS) {
            throw new TableActionException('Only a successful backup can be pinned');
        }

        $this->agent->sendToAgent(
            HilosSignalConstants::BACKUP_AGENT_SET_KEEP,
            new BackupSetKeepSignalData($dto->backupId, $dto->keep, $acceptKey),
        );
    }

    /**
     * Validates the target against the whole restore gate and routes it to the backup agent.
     *
     * Every check lives in {@see RestoreUiGate} rather than here, so the decision can be tested
     * without a page: the environment this installation runs in, the archive's presence, its
     * status and checksum, its migration levels against this code ({@see RestoreMigrationGuard}),
     * whether the subsystem is busy, and the ENV matrix ({@see RestoreEnvGuard}) for this
     * archive/target pair. The environment is re-checked on the action and not only on the
     * button, because a client is not the source of truth about where it runs - and neither is
     * it about the migration levels, which the page it rendered from may have judged against
     * code that has since been restarted.
     *
     * The scope travels from the index row, never from the client: it says how the archive was
     * captured, which is something only the record that produced it knows.
     *
     * As with a create, the ack answers acceptance and not the run. A restore freezes the node
     * for minutes; the requester's accept key rides along so protected mode keeps that one
     * connection alive, addresses the progress frames to it, and tells it if the agent refuses
     * the run after all.
     *
     * Who the requester is travels beside their connection, because the outcome outlives both:
     * the agent turns the user id into identity pairs before the database is replaced, so the
     * finished restore can be announced to that person in the database that replaced it
     * (HIL-279). It is read here, where a browser request still exists to read it from.
     *
     * @param string $acceptKey Accept key of the requesting connection, kept alive through the freeze
     * @param BackupRestoreActionDTO $dto Restore action payload
     * @throws TableActionException When the environment, the archive or the subsystem's state refuses
     *     the restore, or the record names no scope to replay
     */
    private function handleRestore(string $acceptKey, BackupRestoreActionDTO $dto): void
    {
        $targetEnv = $this->currentEnv();
        $histories = $this->histories();
        $row = $histories === null ? null : $histories[$dto->backupId];
        $envVerdict = $row === null || $targetEnv === null
            ? null
            : RestoreEnvGuard::decide(AppEnv::fromString($row->env), $targetEnv, force: false);
        // The levels ride in the index row, so the sidecar is not read from disk to answer a click.
        $migrationVerdict = $row === null
            ? null
            : RestoreMigrationGuard::decide($row->connections, RestoreMigrationGuard::codeMigrationIndex());

        $refusal = RestoreUiGate::decide(
            $targetEnv,
            $dto->backupId,
            $row,
            $this->isBusy(),
            $envVerdict,
            $migrationVerdict,
        )->reason;
        if ($refusal !== null) {
            throw new TableActionException($refusal);
        }

        $scope = $row === null ? null : BackupScope::fromString($row->scope);
        if ($scope === null || $envVerdict === null) {
            // The gate has already refused a missing row and an unnamed environment, so what is
            // left of this guard is the real case: a record whose scope does not parse, which the
            // engine has no way to replay.
            throw new TableActionException("Backup {$dto->backupId} does not name a scope to restore");
        }

        $this->agent->sendToAgent(
            HilosSignalConstants::BACKUP_AGENT_RESTORE,
            new BackupRestoreSignalData(
                $dto->backupId,
                $scope->value,
                $envVerdict->decision->value,
                $acceptKey,
                Hilos::$browser?->resolveActionUserId($acceptKey),
            ),
        );
    }

    /**
     * Tells the subscribing client what this environment offers for restoring.
     *
     * The button is a property of the installation, not of a row, so it is answered once at
     * subscribe rather than per row. Production - and an installation whose APP_ENV names no
     * known environment - gets `uiEnabled: false` and shows the CLI instruction instead; the
     * environment name travels with it because the confirmation modal names the pair the
     * operator is about to bridge (archive's environment → this one).
     *
     * @param PageRouteParams $params Route params from page subscription (unused; the page takes none)
     * @return PagePayload Restore section of the page data
     */
    protected function buildPagePayload(PageRouteParams $params): PagePayload
    {
        $targetEnv = $this->currentEnv();

        return new PagePayload(data: [
            self::RESTORE_SECTION => [
                self::RESTORE_UI_ENABLED => $targetEnv !== null && $targetEnv !== AppEnv::PROD,
                self::RESTORE_TARGET_ENV => $targetEnv?->value,
            ],
        ]);
    }

    /**
     * Reads the environment this installation runs in.
     *
     * An installation that cannot name its environment reads as null, and every reader of this
     * treats that as production: the destructive surface is withheld from anyone who cannot say
     * they are not live. That is why the failure degrades here instead of propagating - APP_ENV
     * is always cataloged, so an unreadable one is a broken install, and the safe answer for a
     * broken install is the same as for production.
     *
     * @return ?AppEnv Current application environment, or null when it is unset or unrecognized
     */
    private function currentEnv(): ?AppEnv
    {
        try {
            return AppEnv::fromString(Hilos::$env->string(EnvConstants::APP_ENV));
        } catch (EnvException) {
            return null;
        }
    }

    /**
     * Reports whether the backup subsystem is occupied by a run of either kind.
     *
     * Not the same question as {@see isInProgress()}: that one asks about one archive, this one
     * asks whether the single child slot is taken at all. The agent enforces it too - it is the
     * lock - but a refusal computed here explains itself before the click instead of after it.
     *
     * @return bool True while a backup or a restore is running
     */
    private function isBusy(): bool
    {
        return (Hilos::$rt?->hilosBackupRuntime?->running ?? false)
            || (Hilos::$rt?->hilosRestoreRuntime?->running ?? false);
    }

    /**
     * Reports whether the given id names the backup currently running.
     *
     * @param string $backupId Target backup id
     * @return bool True when a backup is running under this id
     */
    private function isInProgress(string $backupId): bool
    {
        return Hilos::$rt?->hilosBackupRuntime?->isRunning($backupId) ?? false;
    }

    /**
     * Resolves the runtime backup index collection, or null when unavailable.
     *
     * The `??` is what makes an unmounted index a null rather than a throw: it asks the
     * runtime context's `__isset()` first, where a bare read would raise
     * RtCollectionNotFoundException.
     *
     * @return ?BackupHistories Backup history index, or null when the BACKUP feature is inactive
     */
    private function histories(): ?BackupHistories
    {
        return Hilos::$rt?->hilosBackupHistories ?? null;
    }
}
