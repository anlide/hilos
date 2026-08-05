<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\Pages\Backup\DTO\BackupCreateActionDTO;
use Hilos\Pages\Backup\DTO\BackupDeleteActionDTO;
use Hilos\Pages\Backup\DTO\BackupSetKeepActionDTO;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;

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

    public const array ACTIONS = [
        HilosSignalConstants::BACKUP_CREATE => BackupCreateActionDTO::class,
        HilosSignalConstants::BACKUP_DELETE => BackupDeleteActionDTO::class,
        HilosSignalConstants::BACKUP_SET_KEEP => BackupSetKeepActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BACKUP,
    ];

    /**
     * Routes backup create, delete, and set-keep actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the action target or state is invalid
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
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

        $row = $this->histories()?->get($dto->backupId);
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
     * @return ?BackupHistories Backup history index, or null when runtime state is unavailable
     */
    private function histories(): ?BackupHistories
    {
        $collection = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);

        return $collection instanceof BackupHistories ? $collection : null;
    }
}
