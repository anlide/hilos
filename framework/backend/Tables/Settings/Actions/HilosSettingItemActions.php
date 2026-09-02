<?php

declare(strict_types=1);

namespace Hilos\Tables\Settings\Actions;

use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Hilos;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Item-level actions for a single framework setting (table layer).
 *
 * Operations: update, delete (orphans only). Uses the setting key as the item
 * identifier and delegates to Hilos::$db->settings[$key]->actions.
 *
 * @property HilosSettingsTable $definition Settings table definition that builds row mutation payloads.
 */
final class HilosSettingItemActions extends TableItemActions
{
    /**
     * Updates setting value and returns mutation for broadcasting.
     *
     * @param mixed $value New setting value (a setting row without a value does not exist)
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws TableActionException When the value is null or the setting key is missing from persisted settings
     * @throws DatabaseException When settings persistence or row reload fails
     * @throws SettingException When catalog default metadata cannot rebuild the row
     */
    public function updateValue(mixed $value): TableRowMutationDTO
    {
        if ($value === null) {
            throw new TableActionException('Use setting_reset to return a cataloged key to its default');
        }

        $dbSetting = $this->settingsCollection()[(string) $this->rowKey]
            ?? throw new TableActionException("Setting '{$this->rowKey}' not found");
        $dbSetting->actions->updateValue($value);

        return $this->mutation(TableMutationType::Update, $this->definition->rowFromSetting($dbSetting));
    }

    /**
     * Deletes orphan setting and returns mutation for broadcasting.
     * Only settings whose key is NOT in catalog can be deleted.
     *
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws TableActionException When the setting is missing or still declared in the catalog
     * @throws DatabaseException When settings persistence fails
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     */
    public function delete(): TableRowMutationDTO
    {
        $setting = $this->settingsCollection()[(string) $this->rowKey]
            ?? throw new TableActionException("Setting '{$this->rowKey}' not found");
        $catalog = Hilos::$setting?->catalog()
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');
        if (!$setting->isOrphan($catalog)) {
            throw new TableActionException('Only orphan settings (not in catalog) can be deleted');
        }
        $setting->actions->delete();

        return $this->mutation(TableMutationType::Delete);
    }

    /**
     * Returns the framework settings DB collection through the narrowed context.
     *
     * @return DbCollectionSettings Settings DB collection
     * @throws DatabaseException When the active database context is not a HilosDbContext
     */
    private function settingsCollection(): DbCollectionSettings
    {
        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            throw new DatabaseException('Settings table actions require a HilosDbContext database context');
        }

        return $db->settings;
    }
}
