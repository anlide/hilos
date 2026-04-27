<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\Actions;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\SettingsTable;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * SettingItemActions - Item-level actions for a single setting (table layer).
 *
 * Operations: update, delete (orphans only).
 * Uses key as item identifier. Delegates to Hilos::$db->settings->findByKey($key)->actions.
 *
 * @property SettingsTable $definition Settings table definition that builds row mutation payloads.
 */
final class SettingItemActions extends TableItemActions
{
    /**
     * Updates setting value and returns mutation for broadcasting.
     *
     * @param mixed $value New setting value
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws TableActionException If setting not found
     */
    public function updateValue(mixed $value): TableRowMutationDTO
    {
        $dbSetting = Hilos::$db->settings->findByKey((string) $this->rowKey);
        if ($dbSetting === null) {
            throw new TableActionException("Setting '{$this->rowKey}' not found");
        }
        $dbSetting->actions->updateValue($value);

        return $this->mutation(TableMutationType::Update, $this->definition->rowFromSetting($dbSetting));
    }

    /**
     * Deletes orphan setting and returns mutation for broadcasting.
     * Only settings whose key is NOT in catalog can be deleted.
     *
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws TableActionException If setting is in catalog (not orphan) or not found
     */
    public function delete(): TableRowMutationDTO
    {
        $setting = Hilos::$db->settings->findByKey((string) $this->rowKey);
        if ($setting === null) {
            throw new TableActionException("Setting '{$this->rowKey}' not found");
        }
        $catalog = SettingsCatalog::getCatalog();
        if (!$setting->isOrphan($catalog)) {
            throw new TableActionException('Only orphan settings (not in catalog) can be deleted');
        }
        $setting->actions->delete();
        return $this->mutation(TableMutationType::Delete);
    }
}
