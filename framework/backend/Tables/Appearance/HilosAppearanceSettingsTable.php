<?php

declare(strict_types=1);

namespace Hilos\Tables\Appearance;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Theme\ThemeSettingsCatalog;

/** Self-snapshot projection of the two theme settings; their library owns writes. */
final class HilosAppearanceSettingsTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'hilosAppearanceSettings';

    private const string ROW_SLOT = 'setting';

    /** @return int Both rows fit in the first window */
    public function windowSize(): int
    {
        return count(ThemeSettingsCatalog::KEYS);
    }

    /**
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Updated row, or null for another source/key
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When a setting's metadata or value is invalid
     * @throws LogicException When a settings collection is misconfigured
     * @throws InvalidArgumentException When a settings lookup is invalid
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::settings) {
            return null;
        }

        $key = $change->row[ObjectSetting::key] ?? $this->settingKeyOf($change->sourceId);
        if (!is_string($key) || !in_array($key, ThemeSettingsCatalog::KEYS, true)) {
            return null;
        }

        return $this->mutation(TableMutationType::Update, $key, $this->row($key));
    }

    /**
     * @param AbstractTableRow $row Setting row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser-row envelope
     * @throws TableRowKeyMissingException When the row carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Field visibility for admin view mode */
    public function wireFields(): array
    {
        return [
            HilosAppearanceSettingsTableRow::rowKey => WireField::notPersonal(),
            HilosAppearanceSettingsTableRow::value => WireField::settingFrom(HilosAppearanceSettingsTableRow::rowKey),
            HilosAppearanceSettingsTableRow::defaultValue => WireField::settingFrom(HilosAppearanceSettingsTableRow::rowKey),
        ];
    }

    /**
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO The two setting rows
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When a setting's metadata or value is invalid
     * @throws LogicException When a settings collection is misconfigured
     * @throws InvalidArgumentException When a settings lookup is invalid
     * @throws TableSearchNotSupportedException When a term arrives without searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        foreach (ThemeSettingsCatalog::KEYS as $key) {
            $rows[] = $this->row($key)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /** Configure the row shape. */
    protected function init(): void
    {
        $this->setRowClass(HilosAppearanceSettingsTableRow::class);
    }

    /**
     * @param string $key Setting key
     * @return HilosAppearanceSettingsTableRow Current value and catalog default
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When a setting's metadata or value is invalid
     * @throws LogicException When the accessor or catalog is misconfigured
     * @throws InvalidArgumentException When a settings lookup is invalid
     */
    private function row(string $key): HilosAppearanceSettingsTableRow
    {
        $settings = Hilos::$setting ?? throw new LogicException('Settings accessor is not initialized');
        $default = $settings->defaultValueFor($key);

        if ($key === ThemeSettingsCatalog::SWITCHING_ENABLED_KEY) {
            if (!is_bool($default)) {
                throw new LogicException('Theme switching default must be boolean');
            }

            return new HilosAppearanceSettingsTableRow($key, $settings[$key]->bool(), $default);
        }

        if (!is_string($default)) {
            throw new LogicException('Default theme must be a string');
        }

        return new HilosAppearanceSettingsTableRow($key, $settings[$key]->string(), $default);
    }

    /**
     * @param string $sourceId Settings row id
     * @return ?string Setting key, or null when its row cannot be read
     * @throws DatabaseException When the settings lookup fails
     * @throws LogicException When the settings collection is misconfigured
     * @throws InvalidArgumentException When the settings lookup is invalid
     */
    private function settingKeyOf(string $sourceId): ?string
    {
        if (!Hilos::$db instanceof HilosDbContext || !ctype_digit($sourceId)) {
            return null;
        }

        return Hilos::$db->settings[(int) $sourceId]?->key;
    }
}
