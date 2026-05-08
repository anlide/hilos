<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\Actions;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\SettingsTable;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * SettingsTableActions - Collection-level actions for the settings table (table layer).
 *
 * Operation: add setting. Delegates to Hilos::$db->settings->actions->add().
 *
 * @property SettingsTable $definition Settings table definition that builds row mutation payloads.
 */
final class SettingsTableActions extends TableActions
{
    /**
     * Adds a setting. Key must exist in catalog. Value defaults from catalog if null.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value (null = use catalog default when reading)
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws HilosException When settings catalog validation or DB persistence fails
     */
    public function add(string $key, mixed $value = null): TableRowMutationDTO
    {
        $catalog = SettingsCatalog::getCatalog();
        $dbSetting = Hilos::$db->settings->actions->add($key, $value, $catalog);

        return $this->mutation(TableMutationType::Create, $dbSetting->key, $this->definition->rowFromSetting($dbSetting));
    }
}
