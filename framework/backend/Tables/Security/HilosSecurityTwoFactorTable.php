<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Auth\SecondFactor\SecondFactorSettings;
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
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Pages\Security\AbstractHilosSecurityTwoFactorPage;

/**
 * Framework two-factor settings table: the six settings of the second factor (HIL-494).
 *
 * A self-snapshot table of one row per setting of {@see SecondFactorSettings}, in the order the
 * administration screen lists them: the value in force and the catalog default. A table and not
 * page data for the reason every admin value is one - a snapshot on subscribe and a redraw after
 * the write, which the tracked action waits for. It reacts to changes of those six settings rows
 * and of nothing else.
 *
 * A project activates it by registering it under a table key and binding that key to the
 * two-factor page ({@see AbstractHilosSecurityTwoFactorPage}) in its page tables.
 */
class HilosSecurityTwoFactorTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosSecurityTwoFactor';

    /** Wire slot the row payload rides under; must match the frontend slot. */
    private const string ROW_SLOT = 'setting';

    /**
     * Declares how many rows the first window carries: all six.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return count(SecondFactorSettings::KEYS);
    }

    /**
     * Builds the row mutation from a change of one of the six settings.
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
     * Queries the six rows.
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

        return new HilosSecurityTwoFactorTableRow(
            rowKey: $key,
            value: $settings->typeFor($key) === SettingsCatalogConstants::TYPE_INTEGER
                ? (string) $settings[$key]->int()
                : $settings[$key]->string(),
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
