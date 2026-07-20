<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup;

use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Hilos;
use Hilos\Pages\Backup\DTO\BackupCreateActionDTO;
use Hilos\Pages\Backup\DTO\BackupDeleteActionDTO;
use Hilos\Pages\Backup\DTO\BackupSetKeepActionDTO;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime;

/**
 * AbstractHilosBackupPage - Abstract base for the Hilos backup list page.
 *
 * Owns the backup subscribe signal and the create / delete / toggle-keep action
 * lifecycle. It never writes to runtime state or files itself (single-writer rule,
 * files=truth): each action is validated synchronously against the runtime index
 * (so an invalid target fails the client's request with a correlated ACTION_ERROR)
 * and then routed to the monopoly {@see \Hilos\Backup\Agent\BackupAgent} through
 * `$this->agent->sendToAgent(...)`, which owns the storage mutation. The generic
 * action ack (ACTION_SUCCESS / ACTION_ERROR, correlated by requestId) reports
 * acceptance; the committed outcome is observed reactively over the live table.
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
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case HilosSignalConstants::BACKUP_CREATE:
                if (!$dto instanceof BackupCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, BackupCreateActionDTO::class, $dto);
                }
                $this->handleCreate($dto);

                break;

            case HilosSignalConstants::BACKUP_DELETE:
                if (!$dto instanceof BackupDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, BackupDeleteActionDTO::class, $dto);
                }
                $this->handleDelete($dto);

                break;

            case HilosSignalConstants::BACKUP_SET_KEEP:
                if (!$dto instanceof BackupSetKeepActionDTO) {
                    throw new InvalidActionPayloadException($action, BackupSetKeepActionDTO::class, $dto);
                }
                $this->handleSetKeep($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Validates the scope and routes a create to the backup agent's guarded path.
     *
     * A busy agent is not an error: the design accepts a manual create while one is
     * running into a single in-memory pending slot, so acceptance is always acked.
     *
     * @param BackupCreateActionDTO $dto Create action payload
     * @throws TableActionException When the scope is not a known backup scope
     */
    private function handleCreate(BackupCreateActionDTO $dto): void
    {
        $scope = BackupScope::fromString($dto->scope);
        if ($scope === null) {
            throw new TableActionException("Invalid backup scope: {$dto->scope}");
        }

        $this->agent->sendToAgent(
            HilosSignalConstants::BACKUP_AGENT_CREATE,
            new BackupCreateSignalData($scope->value),
        );
    }

    /**
     * Validates the target and routes a delete to the backup agent's shared delete path.
     *
     * Deleting the in-progress backup is rejected; deleting an already-removed backup
     * is not (the agent no-ops it), so a permissive delete stays idempotent.
     *
     * @param BackupDeleteActionDTO $dto Delete action payload
     * @throws TableActionException When the id is empty or names the in-progress backup
     */
    private function handleDelete(BackupDeleteActionDTO $dto): void
    {
        if ($dto->backupId === '') {
            throw new TableActionException('Backup id is required');
        }
        if ($this->isInProgress($dto->backupId)) {
            throw new TableActionException('Cannot delete a backup that is in progress');
        }

        $this->agent->sendToAgent(
            HilosSignalConstants::BACKUP_AGENT_DELETE,
            new BackupDeleteSignalData($dto->backupId),
        );
    }

    /**
     * Validates the target and routes a keep toggle to the backup agent.
     *
     * Only a successful, completed backup can be pinned; an in-progress or error
     * record is rejected with a correlated ACTION_ERROR.
     *
     * @param BackupSetKeepActionDTO $dto Set-keep action payload
     * @throws TableActionException When the id is empty, missing, in progress, or not a success
     */
    private function handleSetKeep(BackupSetKeepActionDTO $dto): void
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
            new BackupSetKeepSignalData($dto->backupId, $dto->keep),
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
        $runtime = Hilos::$rt?->getStateItem(BackupRuntime::RT_ITEM);

        return $runtime instanceof BackupRuntime
            && $runtime->running
            && $runtime->currentBackupId === $backupId;
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
