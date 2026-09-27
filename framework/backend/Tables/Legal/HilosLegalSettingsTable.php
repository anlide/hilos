<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Legal\LegalSettings;
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

/**
 * Self-snapshot values and defaults for the two legal settings; writes stay with their library.
 */
class HilosLegalSettingsTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosLegalSettings';

    /** Wire slot the row payload rides under; must match the frontend slot. */
    private const string ROW_SLOT = 'setting';

    /**
     * Declares how many rows the first window carries: all two.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return count(LegalSettings::KEYS);
    }

    /**
     * Builds the row mutation from a change of one of the two settings.
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
        if (!is_string($key) || !in_array($key, LegalSettings::KEYS, true)) {
            return null;
        }

        return $this->mutation(TableMutationType::Update, $key, $this->row($key));
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
     * Queries the two rows.
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
        foreach (LegalSettings::KEYS as $key) {
            $rows[] = $this->row($key)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Configures the row shape used by the table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosLegalSettingsTableRow::class);
    }

    /**
     * Builds the row of one setting.
     *
     * @param string $key Setting key
     * @return HilosLegalSettingsTableRow The row
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    private function row(string $key): HilosLegalSettingsTableRow
    {
        $settings = Hilos::$setting ?? throw new LogicException('Settings accessor is not initialized');
        $default = $settings->defaultValueFor($key);
        if (!is_scalar($default)) {
            throw new LogicException("Legal setting '{$key}' has a default that is not a single value");
        }

        return new HilosLegalSettingsTableRow(
            rowKey: $key,
            value: $settings[$key]->string(),
            defaultValue: (string) $default,
        );
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

        return Hilos::$db->settings[(int) $sourceId]?->key;
    }
}
