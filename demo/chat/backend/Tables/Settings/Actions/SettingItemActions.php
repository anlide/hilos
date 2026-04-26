<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings\Actions;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * SettingItemActions - Item-level actions for a single setting (table layer).
 *
 * Operations: update, delete (orphans only).
 * Uses key as item identifier. Delegates to Hilos::$db->settings->findByKey($key)->actions.
 */
final class SettingItemActions extends TableItemActions
{
    /**
     * Updates setting value and returns mutation for broadcasting.
     *
     * @param array<string, mixed> $data Keys: 'value' (required), optionally 'type'
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws TableActionException If setting not found
     */
    public function update(array $data): TableMutationEntry
    {
        $dbSetting = Hilos::$db->settings->findByKey((string) $this->rowKey);
        if ($dbSetting === null) {
            throw new TableActionException("Setting '{$this->rowKey}' not found");
        }
        $dbSetting->actions->update($data);
        return $this->mutation(TableMutationType::Updated, $this->definition->makeRow($dbSetting->toArray()));
    }

    /**
     * Deletes orphan setting and returns mutation for broadcasting.
     * Only settings whose key is NOT in catalog can be deleted.
     *
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws TableActionException If setting is in catalog (not orphan) or not found
     */
    public function delete(): TableMutationEntry
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
        return $this->mutation(TableMutationType::Deleted);
    }
}
