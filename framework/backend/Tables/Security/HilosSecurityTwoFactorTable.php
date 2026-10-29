<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\AdminViewMode\WireField;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
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
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Pages\Security\AbstractHilosSecurityTwoFactorPage;

/**
 * Framework two-factor settings table: the eight settings of the second factor (HIL-494, HIL-1303).
 *
 * A self-snapshot table of one row per setting of {@see SecondFactorSettings}, in the order the
 * administration screen lists them: the value in force and the catalog default, a switch read as
 * 'true' or 'false'. A table and not page data for the reason every admin value is one - a snapshot
 * on subscribe and a redraw after the write, which the tracked action waits for. It reacts to
 * changes of those eight settings rows and of nothing else. A change of the general switch redraws
 * all eight, because every row below it says whether it is locked by that switch.
 *
 * A project activates it by registering it under a table key and binding that key to the
 * two-factor page ({@see AbstractHilosSecurityTwoFactorPage}) in its page tables.
 */
class HilosSecurityTwoFactorTable extends TableDefinition implements SelfSnapshotTable, MultiRowViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosSecurityTwoFactor';

    /** Wire slot the row payload rides under; must match the frontend slot. */
    private const string ROW_SLOT = 'setting';

    /** Text of a switch that is on, as the screen reads it. */
    private const string SWITCH_ON = 'true';

    /** Text of a switch that is off, as the screen reads it. */
    private const string SWITCH_OFF = 'false';

    /** Why a row below the general switch cannot be changed while the second factor is off, as the screen says it. */
    private const string LOCKED_WHILE_OFF = 'Turn on two-step verification to change this setting.';

    /**
     * Declares how many rows the first window carries: all eight.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return count(SecondFactorSettings::KEYS);
    }

    /**
     * Builds the row mutation from a change of one of the eight settings.
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Row mutation, or null when the change is about another setting
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::settings) {
            return null;
        }

        $key = $change->row[ObjectSetting::key] ?? $this->settingKeyOf($change->sourceId);
        if (!is_string($key) || !in_array($key, SecondFactorSettings::KEYS, true)) {
            return null;
        }

        return $this->mutation(TableMutationType::Update, $key, $this->row($key));
    }

    /**
     * Builds the row mutations from a change of one of the eight settings.
     *
     * A change of any setting but the general switch redraws its own row. A change of the general
     * switch - written, stored for the first time or taken back to its default - redraws all eight,
     * because whether each row below it is locked follows that switch.
     *
     * @param SourceChange $change Source change
     * @return list<TableRowMutationDTO> Row mutations, empty when the change is about another setting
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        $mutation = $this->buildMutationForSourceEvent($change);
        if ($mutation === null) {
            return [];
        }
        if ($mutation->rowKey !== SecondFactorSettings::ENABLED_KEY) {
            return [$mutation];
        }

        $mutations = [];
        foreach (SecondFactorSettings::KEYS as $key) {
            $mutations[] = $this->mutation(TableMutationType::Update, $key, $this->row($key));
        }

        return $mutations;
    }

    /**
     * Serializes the row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Setting row from this table's snapshot or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Declares where each field of a two-factor setting row comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * Row key is not personal; value and defaultValue follow the visibility of that settings catalog key.
     * editDisabledReason follows the general switch's key: the sentence is there exactly when that switch is off,
     * so a viewer the switch is hidden from must not read its position out of the reason.
     *
     * @return array<string, WireField>
     */
    public function wireFields(): array
    {
        return [
            HilosSecurityTwoFactorTableRow::rowKey => WireField::notPersonal(),
            HilosSecurityTwoFactorTableRow::value => WireField::settingFrom(HilosSecurityTwoFactorTableRow::rowKey),
            HilosSecurityTwoFactorTableRow::defaultValue => WireField::settingFrom(HilosSecurityTwoFactorTableRow::rowKey),
            HilosSecurityTwoFactorTableRow::editDisabledReason => WireField::setting(SecondFactorSettings::ENABLED_KEY),
        ];
    }

    /**
     * Queries the eight rows.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Settings table snapshot
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        foreach (SecondFactorSettings::KEYS as $key) {
            $rows[] = $this->row($key)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Configures the row shape used by the table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosSecurityTwoFactorTableRow::class);
    }

    /**
     * Builds the row of one setting.
     *
     * Every row but the general switch carries why it is locked while that switch is off, and null while it is on.
     *
     * @param string $key Setting key
     * @return HilosSecurityTwoFactorTableRow The row
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    private function row(string $key): HilosSecurityTwoFactorTableRow
    {
        $settings = Hilos::$setting ?? throw new LogicException('Settings accessor is not initialized');
        $default = $settings->defaultValueFor($key);
        if (!is_scalar($default)) {
            throw new LogicException("Second-factor setting '{$key}' has a default that is not a single value");
        }
        $editDisabledReason = $key === SecondFactorSettings::ENABLED_KEY || $settings[SecondFactorSettings::ENABLED_KEY]->bool()
            ? null
            : self::LOCKED_WHILE_OFF;

        if ($settings->typeFor($key) === SettingsCatalogConstants::TYPE_BOOLEAN) {
            return new HilosSecurityTwoFactorTableRow(
                rowKey: $key,
                value: $this->switchText($settings[$key]->bool()),
                defaultValue: $this->switchText((bool) $default),
                editDisabledReason: $editDisabledReason,
            );
        }

        return new HilosSecurityTwoFactorTableRow(
            rowKey: $key,
            value: $settings->typeFor($key) === SettingsCatalogConstants::TYPE_INTEGER
                ? (string) $settings[$key]->int()
                : $settings[$key]->string(),
            defaultValue: (string) $default,
            editDisabledReason: $editDisabledReason,
        );
    }

    /**
     * @param bool $on Position of a switch
     * @return string The position as the screen reads it
     */
    private function switchText(bool $on): string
    {
        return $on ? self::SWITCH_ON : self::SWITCH_OFF;
    }

    /**
     * Names the setting a settings row carries, when the change named only the row.
     *
     * An update carries only the columns that moved, and the key never moves, so an update of a
     * value arrives without its key and is recognized by its row.
     *
     * @param string $sourceId Settings row id
     * @return ?string Setting key of the row, or null when it cannot be read
     * @throws DatabaseException When the settings lookup fails
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    private function settingKeyOf(string $sourceId): ?string
    {
        if (!Hilos::$db instanceof HilosDbContext || !ctype_digit($sourceId)) {
            return null;
        }

        return isset(Hilos::$db->settings[(int) $sourceId]) ? Hilos::$db->settings[(int) $sourceId]->key : null;
    }
}
