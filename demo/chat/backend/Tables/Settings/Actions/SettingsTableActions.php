<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\Actions;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * SettingsTableActions - Collection-level actions for the settings table (table layer).
 *
 * Operation: add setting. Delegates to Hilos::$db->settings->actions->add().
 */
final class SettingsTableActions extends TableActions
{
    /**
     * Adds a setting. Key must exist in catalog. Value defaults from catalog if null.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value (null = use default_value from catalog)
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws HilosException On db or validation error
     */
    public function add(string $key, mixed $value = null): TableRowMutationDTO
    {
        $catalog = SettingsCatalog::getCatalog();
        $dbSetting = Hilos::$db->settings->actions->add($key, $value, $catalog);

        return $this->mutation(TableMutationType::Create, $dbSetting->key, $this->definition->makeRow($dbSetting->toArray()));
    }
}
