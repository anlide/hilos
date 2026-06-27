<?php

declare(strict_types=1);

namespace Hilos\Tables\Settings\Actions;

use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Collection-level actions for the framework settings table (table layer).
 *
 * Operation: add setting. Delegates to Hilos::$db->settings->actions->add().
 *
 * @property HilosSettingsTable $definition Settings table definition that builds row mutation payloads.
 */
final class HilosSettingsTableActions extends TableActions
{
    /**
     * Adds a setting. Key must exist in catalog. Value defaults from catalog if null.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value (null = use catalog default when reading)
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws DatabaseException When the active database context is not a HilosDbContext
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When settings catalog validation or DB persistence fails
     */
    public function add(string $key, mixed $value = null): TableRowMutationDTO
    {
        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            throw new DatabaseException('Settings table actions require a HilosDbContext database context');
        }
        $catalog = Hilos::$setting?->catalog()
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');
        $dbSetting = $db->settings->actions->add($key, $value, $catalog);

        return $this->mutation(TableMutationType::Create, $dbSetting->key, $this->definition->rowFromSetting($dbSetting));
    }
}
