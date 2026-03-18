<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\Actions\SettingItemActions;
use Demo\Chat\Tables\Settings\SettingsTableDataSource;
use Demo\Chat\Tables\Settings\Actions\SettingsTableActions;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * SettingsTable - Table definition merging catalog (PHP config) and DB.
 *
 * Operations: change setting, delete orphan (via item actions).
 * Collection-level add via SettingsTableActions.
 */
final class SettingsTable extends TableDefinition
{
    /**
     * Provides entity data source for the settings collection.
     *
     * @return TableDataSourceInterface Entity table data source for settings
     */
    protected function createDataSource(): TableDataSourceInterface
    {
        return new SettingsTableDataSource();
    }

    /**
     * Configures table-level actions (add) and item-level actions (update, delete orphan).
     */
    protected function init(): void
    {
        $this->setActionsClass(SettingsTableActions::class);
        $this->setItemActionsClass(SettingItemActions::class);
    }
}
