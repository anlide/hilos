<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Backup\BackupConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Pages\Backup\AbstractHilosBackupPage;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Actions\Collection\BackupHistoriesActions;
use Hilos\Runtime\View\Actions\Item\BackupHistoryActions;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Backup\HilosBackupHistoryTable;

/**
 * Backup subsystem: catalog, agent pair, history index and admin page.
 *
 * The runtime index is mounted here rather than by the project because the backup agent and
 * the backup page run in different workers: the index only reaches the page through the RT
 * sync its actions emit, so it must carry the framework's own representation. A project that
 * mounted it by hand was declaring the feature a second time, in a place where a typo in the
 * key or a missing setRepresent() produced a page that simply stayed empty.
 */
final class BackupFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Backup feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::BACKUP;
    }

    /**
     * @return FeatureRequirements Backup page and its history table binding, the agent pair,
     *     the catalog, and the two child commands the supervisor spawns (create and restore)
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [AbstractHilosBackupPage::class],
            requiredAgents: [HilosAgentType::HILOS_BACKUP],
            requiredTables: [HilosBackupHistoryTable::class],
            requiredPageTables: [AbstractHilosBackupPage::class => HilosBackupHistoryTable::class],
            requiredCatalogConstant: 'BACKUP_CATALOG',
            requiredCliCommands: [BackupConstants::RUN_COMMAND, BackupConstants::RESTORE_RUN_COMMAND],
        );
    }

    /**
     * Carries runtime state, declared beside the mount it describes.
     *
     * @return bool Always true
     */
    public function mountsRuntime(): bool
    {
        return true;
    }

    /**
     * Mounts the backup history index with its framework representation, and the create
     * and restore runtime rows.
     *
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When the history index is represented before it is mounted
     */
    public function mount(RtContext $context): void
    {
        $context->mountFeatureCollection(StateBackupHistory::RT_COLLECTION, StateBackupHistories::init());
        $context->setRepresent(
            StateBackupHistory::RT_COLLECTION,
            BackupHistories::class,
            BackupHistoriesActions::class,
            BackupHistoryActions::class,
        );
        $context->mountFeatureItem(StateBackupRuntime::RT_ITEM, StateBackupRuntime::create());
        $context->mountFeatureItem(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::create());
    }
}
