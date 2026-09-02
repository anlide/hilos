<?php

declare(strict_types=1);

namespace Hilos\Tables\Settings\Actions;

use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Collection-level actions for the framework settings table (table layer).
 *
 * Operations: set a custom value on a cataloged key, and reset a cataloged key back
 * to its catalog default. Both are collection-level because the row may not exist:
 * what they address is the catalog KEY, not a stored row.
 *
 * Adding is idempotent by key: it writes through the existing row when one is already
 * stored, and inserts only when the key has no row at all.
 *
 * @property HilosSettingsTable $definition Settings table definition that builds row mutation payloads.
 */
final class HilosSettingsTableActions extends TableActions
{
    /**
     * Sets a custom value on a cataloged key, inserting the row only when absent.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value to store as the override (a setting row without a value does not exist)
     * @return TableRowMutationDTO Row mutation DTO for broadcast — an update when the row existed
     * @throws TableActionException When the value is null, which is a reset and has its own action
     * @throws DatabaseException When the active database context is not a HilosDbContext
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws HilosException When settings catalog validation, the write guard, or DB persistence fails
     */
    public function add(string $key, mixed $value): TableRowMutationDTO
    {
        if ($value === null) {
            throw new TableActionException('Use setting_reset to return a cataloged key to its default');
        }

        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            throw new DatabaseException('Settings table actions require a HilosDbContext database context');
        }
        $catalog = Hilos::$setting?->catalog()
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');

        // Setting a custom value on a key that already has a row is an update, not an
        // insert: insert-only here would hit the unique key — which is also what a second
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

    /**
     * Resets a cataloged key back to its catalog default by removing its stored row.
     *
     * Idempotent: a key with no row is already on its default, and a second admin
     * resetting first must not turn into an error on this one's screen. The broadcast
     * is an update to the placeholder row and not a delete, because the cataloged key
     * stays in the window on its own default ({@see HilosSettingsTable::rowForKey()}).
     *
     * @param string $key Setting key (must be in catalog)
     * @return TableRowMutationDTO Row mutation DTO for broadcast — an update to the placeholder row
     * @throws TableActionException When the key is an orphan and has no catalog default to reset to
     * @throws DatabaseException When the active database context is not a HilosDbContext
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws SettingException When catalog default metadata cannot rebuild the placeholder row
     * @throws HilosException When the write guard or DB persistence fails
     */
    public function reset(string $key): TableRowMutationDTO
    {
        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            throw new DatabaseException('Settings table actions require a HilosDbContext database context');
        }
        $catalog = Hilos::$setting?->catalog()
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');

        if (!array_key_exists($key, $catalog)) {
            throw new TableActionException("Setting '{$key}' is an orphan and has no catalog default to reset to");
        }

        $existing = $db->settings[$key] ?? null;
        $existing?->actions->delete();

        $row = $this->definition->rowForKey($key)
            ?? throw new TableActionException("Setting '{$key}' left no placeholder row after the reset");

        return $this->mutation(TableMutationType::Update, $key, $row);
    }
}
