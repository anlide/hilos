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
 * Operation: set a custom value on a cataloged key. Idempotent by key: it writes
 * through the existing row when one is already stored (a stored NULL means "no
 * override, inherit the default" — the row exists), and inserts only when the key
 * has no row at all.
 *
 * @property HilosSettingsTable $definition Settings table definition that builds row mutation payloads.
 */
final class HilosSettingsTableActions extends TableActions
{
    /**
     * Sets a custom value on a cataloged key, inserting the row only when absent.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value (null = use catalog default when reading)
     * @return TableRowMutationDTO Row mutation DTO for broadcast — an update when the row existed
     * @throws DatabaseException When the active database context is not a HilosDbContext
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When settings catalog validation, the write guard, or DB persistence fails
     */
    public function add(string $key, mixed $value = null): TableRowMutationDTO
    {
        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            throw new DatabaseException('Settings table actions require a HilosDbContext database context');
        }
        $catalog = Hilos::$setting?->catalog()
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');

        // Setting a custom value on a key that already has a row is an update, not
        // an insert: a stored NULL means "no override, inherit", so the row exists.
        // Insert-only here would hit the unique key — which is also what a second
        // admin sees when the first one created the row between render and submit.
        $existing = $db->settings[$key] ?? null;
        if ($existing !== null) {
            $existing->actions->updateValue($value);

            return $this->mutation(
                TableMutationType::Update,
                $existing->key,
                $this->definition->rowFromSetting($existing),
            );
        }

        $dbSetting = $db->settings->actions->add($key, $value, $catalog);

        return $this->mutation(TableMutationType::Create, $dbSetting->key, $this->definition->rowFromSetting($dbSetting));
    }
}
