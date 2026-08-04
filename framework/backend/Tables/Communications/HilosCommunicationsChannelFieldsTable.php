<?php

declare(strict_types=1);

namespace Hilos\Tables\Communications;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\ChannelConfigResolver;
use Hilos\Notification\Delivery\DeliveryChannelSettings;

/**
 * Framework channel-config fields table: one row per config field of every channel (HIL-200).
 *
 * A self-snapshot table built from the channel registry, not a DB source. It carries
 * the fields of all channels; the channel page filters the rows to its own channel
 * by the {@see HilosCommunicationsChannelFieldsTableRow::channel} column (a route
 * param never reaches a table query, so the table is not per-channel). Each row
 * projects a {@see ChannelConfigField} plus its resolved value and source from
 * {@see ChannelConfigResolver} — a secret field's value is never sent to the browser.
 * A new channel's fields appear here without any table edit. The writable source
 * behind a row is the settings collection (the admin override keys), so the table
 * reacts to settings changes to redraw the affected field row.
 *
 * A project activates the table by registering it under a table key and binding that
 * key to the communications channel page in {@see Hilos::PAGE_TABLES}.
 */
class HilosCommunicationsChannelFieldsTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosCommunicationsChannelFields';

    /** Wire slot the row payload rides under; must match the frontend fields slot. */
    private const string ROW_SLOT = 'field';

    /**
     * Builds a field row mutation from a settings source change.
     *
     * A change to a field's override key redraws that one field row (its value and
     * source shift).
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Field row mutation, or null when the change does not affect this table
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings catalog metadata or value is invalid
     * @throws EnvException When an env-backed field value is invalid for its type
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::settings) {
            return null;
        }

        $key = $this->settingKeyFromSourceChange($change);
        if ($key === '') {
            return null;
        }

        $match = $this->fieldForSettingKey($key);
        if ($match === null) {
            return null;
        }

        [$descriptor, $field] = $match;

        return $this->mutation(
            TableMutationType::Update,
            $key,
            $this->rowForField($descriptor, $field),
        );
    }

    /**
     * Serializes one field row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Field table row from this table's snapshot or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->getRowKey() ?? '',
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Queries the fields rows, one per config field of every registered channel.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Fields table snapshot
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings catalog metadata or value is invalid
     * @throws EnvException When an env-backed field value is invalid for its type
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        foreach ($this->channels() as $descriptor) {
            foreach ($descriptor->configFields() as $field) {
                $rows[] = $this->rowForField($descriptor, $field)->toArray();
            }
        }

        return InMemoryTableFilter::apply($rows, $query);
    }

    /**
     * Configures the row shape used by the fields table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosCommunicationsChannelFieldsTableRow::class);
    }

    /**
     * The registered delivery channel descriptors.
     *
     * A seam the framework reads from the project channel registry; tests bind an
     * in-memory set.
     *
     * @return iterable<string, AbstractDeliveryChannel> Channel descriptors keyed by name
     */
    protected function channels(): iterable
    {
        return Hilos::notificationChannelRegistryClass()::all();
    }

    /**
     * Projects a channel config field and its resolved value into a table row.
     *
     * @param AbstractDeliveryChannel $descriptor Owning channel descriptor
     * @param ChannelConfigField $field Config field descriptor
     * @return HilosCommunicationsChannelFieldsTableRow Field table row
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings catalog metadata or value is invalid
     * @throws EnvException When an env-backed field value is invalid for its type
     */
    private function rowForField(
        AbstractDeliveryChannel $descriptor,
        ChannelConfigField $field,
    ): HilosCommunicationsChannelFieldsTableRow {
        $resolved = new ChannelConfigResolver()->resolve($descriptor->name(), $field);

        return new HilosCommunicationsChannelFieldsTableRow(
            rowKey: DeliveryChannelSettings::fieldKey($descriptor->name(), $field->key),
            channel: $descriptor->name(),
            field: $field->key,
            label: $field->label,
            type: $field->type,
            value: $resolved->value,
            valueSource: $resolved->source->value,
            secret: $field->secret,
            editable: !$field->secret,
        );
    }

    /**
     * Finds the channel and field a settings key belongs to, or null when none.
     *
     * @param string $key Setting key
     * @return array{0: AbstractDeliveryChannel, 1: ChannelConfigField}|null Owning channel and field, or null
     */
    private function fieldForSettingKey(string $key): ?array
    {
        foreach ($this->channels() as $descriptor) {
            foreach ($descriptor->configFields() as $field) {
                if ($key === DeliveryChannelSettings::fieldKey($descriptor->name(), $field->key)) {
                    return [$descriptor, $field];
                }
            }
        }

        return null;
    }

    /**
     * Resolves the settings key carried by a settings source change.
     *
     * @param SourceChange $change Settings source change
     * @return string Setting key, or an empty string when it cannot be resolved
     */
    private function settingKeyFromSourceChange(SourceChange $change): string
    {
        $key = $change->row[ObjectSetting::key] ?? null;
        if (is_string($key) || is_int($key)) {
            return (string) $key;
        }

        return $change->sourceId;
    }
}
